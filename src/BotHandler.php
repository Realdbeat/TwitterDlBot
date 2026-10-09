<?php
namespace TwitterDlBot;

use CURLFile;

/**
 * Handles incoming Telegram updates and orchestrates responses.
 */
class BotHandler {
    private TelegramBot $bot;
    private TwitterDownloader $downloader;
    private TikTokDownloader $tiktokDownloader;
    private array $config;

    public function __construct(
        TelegramBot $bot,
        TwitterDownloader $downloader,
        array $config,
        ?TikTokDownloader $tiktokDownloader = null
    ) {
        $this->bot = $bot;
        $this->downloader = $downloader;
        $this->config = $config;
        $this->tiktokDownloader = $tiktokDownloader ?? new TikTokDownloader($config['http_timeout'] ?? 30);
    }

    /**
     * Handles a single update received from Telegram.
     */
    public function handleUpdate(array $update): void {
        // Handle Callback Query (inline buttons)
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        // Handle incoming message
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
            return;
        }
    }

    /**
     * Process text message.
     */
    private function handleMessage(array $message): void {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? $message['caption'] ?? '');
        $isPrivate = ($message['chat']['type'] ?? '') === 'private';

        if (!$chatId || $text === '') {
            return;
        }

        // Handle slash commands
        if (str_starts_with($text, '/')) {
            $command = strtolower(explode(' ', $text)[0]);
            // Strip @botusername from command
            $command = explode('@', $command)[0];

            switch ($command) {
                case '/start':
                    $this->sendStartMessage($chatId, $message['from']['first_name'] ?? 'Friend');
                    return;

                case '/help':
                    $this->sendHelpMessage($chatId);
                    return;

                case '/about':
                    $this->sendAboutMessage($chatId);
                    return;
            }
        }

        // Extract tweet and tiktok links from text
        $tweetLinks = $this->downloader->extractTweetLinks($text);
        $tiktokLinks = $this->tiktokDownloader->extractTikTokLinks($text);

        if (empty($tweetLinks) && empty($tiktokLinks)) {
            if ($isPrivate) {
                $this->bot->sendMessage(
                    $chatId,
                    "👋 Please send a valid <b>Twitter / X</b> or <b>TikTok</b> link.\n\n" .
                    "<b>Examples:</b>\n" .
                    "• <code>https://x.com/username/status/1234567890</code>\n" .
                    "• <code>https://www.tiktok.com/@username/video/1234567890</code>\n" .
                    "• <code>https://vm.tiktok.com/ZMxxxxxx/</code>\n\n" .
                    "Type /help for instructions.",
                    [
                        'reply_to_message_id' => $message['message_id'] ?? null
                    ]
                );
            }
            return;
        }

        // Limit processing to max 3 links each to prevent abuse
        if (!empty($tweetLinks)) {
            $tweetLinks = array_slice($tweetLinks, 0, 3);
            foreach ($tweetLinks as $item) {
                $this->processTweetLink($chatId, $item, $message['message_id'] ?? null);
            }
        }

        if (!empty($tiktokLinks)) {
            $tiktokLinks = array_slice($tiktokLinks, 0, 3);
            foreach ($tiktokLinks as $item) {
                $this->processTikTokLink($chatId, $item, $message['message_id'] ?? null);
            }
        }
    }

    /**
     * Process a single tweet link.
     */
    private function processTweetLink(int|string $chatId, array $item, ?int $replyToId): void {
        $statusMsg = $this->bot->sendMessage(
            $chatId,
            "🔍 <i>Searching for video in tweet...</i>",
            ['reply_to_message_id' => $replyToId]
        );
        $statusMsgId = $statusMsg['result']['message_id'] ?? null;

        $this->bot->sendChatAction($chatId, 'upload_video');

        $tweetInfo = $this->downloader->getVideoInfo($item['id'], $item['user']);

        if ($tweetInfo === null) {
            $errorText = "❌ <b>Could not fetch tweet!</b>\n\n" .
                "Possible reasons:\n" .
                "• The post is from a private or suspended account\n" .
                "• The post was deleted\n" .
                "• Twitter/X temporarily rate-limited the request\n\n" .
                "Please verify the link and try again in a moment.";

            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, $errorText);
            } else {
                $this->bot->sendMessage($chatId, $errorText, ['reply_to_message_id' => $replyToId]);
            }
            return;
        }

        // Check if there are videos
        if (empty($tweetInfo['videos'])) {
            $noVideoText = "ℹ️ <b>No video found!</b>\n\n";
            if (!empty($tweetInfo['has_photos'])) {
                $noVideoText .= "This tweet contains <b>images/photos</b>, but no video or GIF. This bot is specifically for downloading videos.";
            } else {
                $noVideoText .= "This tweet does not contain any video or GIF media.";
            }

            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, $noVideoText);
            } else {
                $this->bot->sendMessage($chatId, $noVideoText, ['reply_to_message_id' => $replyToId]);
            }
            return;
        }

        // We have videos! Update status
        if ($statusMsgId) {
            $this->bot->editMessageText($chatId, $statusMsgId, "📥 <i>Video found! Sending to Telegram...</i>");
        }

        // Format caption
        $author = htmlspecialchars($tweetInfo['author_name'], ENT_QUOTES, 'UTF-8');
        $handle = htmlspecialchars($tweetInfo['author_handle'], ENT_QUOTES, 'UTF-8');
        $textExcerpt = '';
        if (!empty($tweetInfo['text'])) {
            $cleanText = htmlspecialchars(trim($tweetInfo['text']), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($cleanText) > 250) {
                $cleanText = mb_substr($cleanText, 0, 247) . '...';
            }
            $textExcerpt = "\n\n<i>\"{$cleanText}\"</i>";
        }

        $caption = "🎬 <b><a href=\"{$tweetInfo['url']}\">{$author}</a></b> (@{$handle}){$textExcerpt}\n\n" .
                   "🔗 <a href=\"{$tweetInfo['url']}\">View on X</a>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🌐 Open Original Post', 'url' => $tweetInfo['url']]
                ]
            ]
        ];

        // Send each video
        $sendSuccess = false;
        foreach ($tweetInfo['videos'] as $index => $video) {
            $this->bot->sendChatAction($chatId, 'upload_video');
            $videoUrl = $video['url'];

            $videoOptions = [
                'caption' => ($index === 0) ? $caption : '',
                'reply_markup' => ($index === 0) ? $keyboard : null,
                'reply_to_message_id' => $replyToId,
                'duration' => !empty($video['duration']) ? (int)$video['duration'] : null,
                'width' => !empty($video['width']) ? (int)$video['width'] : null,
                'height' => !empty($video['height']) ? (int)$video['height'] : null
            ];

            // 1. First attempt: Send by URL directly to Telegram
            $result = $this->bot->sendVideo($chatId, $videoUrl, $videoOptions);

            if ($result['ok'] ?? false) {
                $sendSuccess = true;
                continue;
            }

            // 2. Second attempt: If URL send failed, download locally to temp and upload via CURLFile
            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, "⏳ <i>Downloading media to server for direct upload...</i>");
            }

            $tempFile = $this->downloader->downloadVideoToTemp(
                $videoUrl,
                $this->config['temp_dir'],
                $this->config['max_file_size_mb']
            );

            if ($tempFile && file_exists($tempFile)) {
                $this->bot->sendChatAction($chatId, 'upload_video');
                $curlFile = new CURLFile($tempFile, 'video/mp4', 'twitter_video.mp4');
                $uploadResult = $this->bot->sendVideo($chatId, $curlFile, $videoOptions);

                // If sendVideo fails, try sendDocument fallback
                if (!($uploadResult['ok'] ?? false)) {
                    $uploadResult = $this->bot->sendDocument($chatId, $curlFile, $videoOptions);
                }

                @unlink($tempFile);

                if ($uploadResult['ok'] ?? false) {
                    $sendSuccess = true;
                    continue;
                }
            }

            // 3. If file exceeds 50MB or all uploads failed, provide direct download link
            $fallbackText = "⚠️ <b>Could not upload video directly!</b>\n\n" .
                "The video may exceed Telegram's 50MB bot upload limit or Telegram rejected the file.\n\n" .
                "👉 <a href=\"{$videoUrl}\"><b>Click here to download/view the video directly</b></a>";

            $this->bot->sendMessage($chatId, $fallbackText, [
                'reply_to_message_id' => $replyToId,
                'reply_markup' => $keyboard
            ]);
            $sendSuccess = true;
        }

        // Clean up the status message
        if ($statusMsgId && $sendSuccess) {
            $this->bot->deleteMessage($chatId, $statusMsgId);
        }
    }

    /**
     * Process a single TikTok link.
     */
    private function processTikTokLink(int|string $chatId, array $item, ?int $replyToId): void {
        $statusMsg = $this->bot->sendMessage(
            $chatId,
            "🔍 <i>Searching for TikTok video...</i>",
            ['reply_to_message_id' => $replyToId]
        );
        $statusMsgId = $statusMsg['result']['message_id'] ?? null;

        $this->bot->sendChatAction($chatId, 'upload_video');

        $tiktokInfo = $this->tiktokDownloader->getVideoInfo($item['url'], $item['id'], $item['user']);

        if ($tiktokInfo === null) {
            $errorText = "❌ <b>Could not fetch TikTok video!</b>\n\n" .
                "Possible reasons:\n" .
                "• The post is from a private account\n" .
                "• The video was deleted or region-restricted\n" .
                "• TikTok temporarily rate-limited the request\n\n" .
                "Please verify the link and try again in a moment.";

            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, $errorText);
            } else {
                $this->bot->sendMessage($chatId, $errorText, ['reply_to_message_id' => $replyToId]);
            }
            return;
        }

        // Check if there are videos
        if (empty($tiktokInfo['videos'])) {
            // Check if photo slideshow
            if (!empty($tiktokInfo['images'])) {
                $this->processTikTokPhotos($chatId, $tiktokInfo, $statusMsgId, $replyToId);
                return;
            }

            $noVideoText = "ℹ️ <b>No video found!</b>\n\nThis TikTok post does not contain any video or slideshow media.";
            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, $noVideoText);
            } else {
                $this->bot->sendMessage($chatId, $noVideoText, ['reply_to_message_id' => $replyToId]);
            }
            return;
        }

        // We have videos! Update status
        if ($statusMsgId) {
            $this->bot->editMessageText($chatId, $statusMsgId, "📥 <i>TikTok video found! Sending to Telegram...</i>");
        }

        // Format caption
        $author = htmlspecialchars($tiktokInfo['author_name'], ENT_QUOTES, 'UTF-8');
        $handle = htmlspecialchars($tiktokInfo['author_handle'], ENT_QUOTES, 'UTF-8');
        $textExcerpt = '';
        if (!empty($tiktokInfo['text'])) {
            $cleanText = htmlspecialchars(trim($tiktokInfo['text']), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($cleanText) > 250) {
                $cleanText = mb_substr($cleanText, 0, 247) . '...';
            }
            $textExcerpt = "\n\n<i>\"{$cleanText}\"</i>";
        }

        $musicLine = '';
        if (!empty($tiktokInfo['music']['title'])) {
            $musicTitle = htmlspecialchars($tiktokInfo['music']['title'], ENT_QUOTES, 'UTF-8');
            $musicLine = "\n🎵 <i>{$musicTitle}</i>";
        }

        $caption = "🎬 <b><a href=\"{$tiktokInfo['url']}\">{$author}</a></b> (@{$handle}){$textExcerpt}{$musicLine}\n\n" .
                   "🔗 <a href=\"{$tiktokInfo['url']}\">View on TikTok</a>";

        $keyboardButtons = [
            [
                ['text' => '🌐 Open on TikTok', 'url' => $tiktokInfo['url']]
            ]
        ];

        // If audio track is available, cache audio info and add download audio button
        if (!empty($tiktokInfo['music']['url'])) {
            $audioId = !empty($tiktokInfo['id']) ? substr($tiktokInfo['id'], -15) : 'last';
            $keyboardButtons[] = [
                ['text' => '🎵 Download Audio (MP3)', 'callback_data' => "tt_aud:{$audioId}"]
            ];

            $audioCacheFile = rtrim($this->config['temp_dir'], '/\\') . DIRECTORY_SEPARATOR . "tt_aud_{$audioId}.json";
            @file_put_contents($audioCacheFile, json_encode([
                'url' => $tiktokInfo['music']['url'],
                'title' => $tiktokInfo['music']['title'] ?? 'Original Sound',
                'author' => $tiktokInfo['author_name'] ?? 'TikTok'
            ]));
        }

        $keyboard = ['inline_keyboard' => $keyboardButtons];

        // Pick best video (HD watermark-free preferred)
        $video = $tiktokInfo['videos'][0];
        $videoUrl = $video['url'];

        $this->bot->sendChatAction($chatId, 'upload_video');

        $videoOptions = [
            'caption' => $caption,
            'reply_markup' => $keyboard,
            'reply_to_message_id' => $replyToId,
            'duration' => !empty($video['duration']) ? (int)$video['duration'] : null,
            'width' => !empty($video['width']) ? (int)$video['width'] : null,
            'height' => !empty($video['height']) ? (int)$video['height'] : null
        ];

        $sendSuccess = false;

        // 1. First attempt: Send by URL directly to Telegram
        $result = $this->bot->sendVideo($chatId, $videoUrl, $videoOptions);

        if ($result['ok'] ?? false) {
            $sendSuccess = true;
        } else {
            // 2. Second attempt: Download locally to temp and upload via CURLFile
            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, "⏳ <i>Downloading media to server for direct upload...</i>");
            }

            $tempFile = $this->tiktokDownloader->downloadVideoToTemp(
                $videoUrl,
                $this->config['temp_dir'],
                $this->config['max_file_size_mb']
            );

            if ($tempFile && file_exists($tempFile)) {
                $this->bot->sendChatAction($chatId, 'upload_video');
                $curlFile = new CURLFile($tempFile, 'video/mp4', 'tiktok_video.mp4');
                $uploadResult = $this->bot->sendVideo($chatId, $curlFile, $videoOptions);

                // If sendVideo fails, try sendDocument fallback
                if (!($uploadResult['ok'] ?? false)) {
                    $uploadResult = $this->bot->sendDocument($chatId, $curlFile, $videoOptions);
                }

                @unlink($tempFile);

                if ($uploadResult['ok'] ?? false) {
                    $sendSuccess = true;
                }
            }

            // 3. Fallback direct download link if upload failed or file exceeds 50MB
            if (!$sendSuccess) {
                $fallbackText = "⚠️ <b>Could not upload video directly!</b>\n\n" .
                    "The video may exceed Telegram's 50MB bot upload limit or Telegram rejected the file.\n\n" .
                    "👉 <a href=\"{$videoUrl}\"><b>Click here to download/view the TikTok video directly</b></a>";

                $this->bot->sendMessage($chatId, $fallbackText, [
                    'reply_to_message_id' => $replyToId,
                    'reply_markup' => $keyboard
                ]);
                $sendSuccess = true;
            }
        }

        // Clean up the status message
        if ($statusMsgId && $sendSuccess) {
            $this->bot->deleteMessage($chatId, $statusMsgId);
        }
    }

    /**
     * Process TikTok photo slideshow posts.
     */
    private function processTikTokPhotos(int|string $chatId, array $tiktokInfo, ?int $statusMsgId, ?int $replyToId): void {
        $images = $tiktokInfo['images'];
        $author = htmlspecialchars($tiktokInfo['author_name'], ENT_QUOTES, 'UTF-8');
        $handle = htmlspecialchars($tiktokInfo['author_handle'], ENT_QUOTES, 'UTF-8');
        $textExcerpt = '';
        if (!empty($tiktokInfo['text'])) {
            $cleanText = htmlspecialchars(trim($tiktokInfo['text']), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($cleanText) > 250) {
                $cleanText = mb_substr($cleanText, 0, 247) . '...';
            }
            $textExcerpt = "\n\n<i>\"{$cleanText}\"</i>";
        }

        $caption = "📸 <b><a href=\"{$tiktokInfo['url']}\">{$author}</a></b> (@{$handle}) [Photo Slideshow]{$textExcerpt}\n\n" .
                   "🔗 <a href=\"{$tiktokInfo['url']}\">View on TikTok</a>";

        if ($statusMsgId) {
            $this->bot->editMessageText($chatId, $statusMsgId, "🖼️ <i>Sending " . count($images) . " TikTok photos...</i>");
        }

        // Build media group (Telegram supports up to 10 photos per media group)
        $chunk = array_slice($images, 0, 10);
        $mediaGroup = [];
        foreach ($chunk as $idx => $imgUrl) {
            $mediaGroup[] = [
                'type' => 'photo',
                'media' => $imgUrl,
                'caption' => ($idx === 0) ? $caption : '',
                'parse_mode' => 'HTML'
            ];
        }

        $res = $this->bot->sendMediaGroup($chatId, $mediaGroup);
        if (!($res['ok'] ?? false)) {
            // Fallback: send first photo with caption
            $this->bot->sendPhoto($chatId, $images[0], [
                'caption' => $caption,
                'reply_to_message_id' => $replyToId
            ]);
        }

        // Send audio if available
        if (!empty($tiktokInfo['music']['url'])) {
            $this->bot->sendAudio($chatId, $tiktokInfo['music']['url'], [
                'title' => $tiktokInfo['music']['title'] ?? 'Original Sound',
                'performer' => $tiktokInfo['author_name'] ?? 'TikTok',
                'caption' => "🎵 Background track: <b>" . htmlspecialchars($tiktokInfo['music']['title'] ?? 'Sound', ENT_QUOTES, 'UTF-8') . "</b>"
            ]);
        }

        if ($statusMsgId) {
            $this->bot->deleteMessage($chatId, $statusMsgId);
        }
    }

    /**
     * Send /start welcome message.
     */
    private function sendStartMessage(int|string $chatId, string $name): void {
        $text = "👋 <b>Hello, " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "!</b>\n\n" .
            "I am your <b>Twitter (X) & TikTok Video Downloader Bot</b>.\n\n" .
            "🚀 <b>How it works:</b>\n" .
            "1. Copy any video link from <b>Twitter / X</b> or <b>TikTok</b>\n" .
            "2. Send or paste it in this chat\n" .
            "3. I'll download and send the video (watermark-free!) right back!\n\n" .
            "💡 <i>Try sending a link now!</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📖 How to Use', 'callback_data' => 'help'],
                    ['text' => 'ℹ️ About', 'callback_data' => 'about']
                ]
            ]
        ];

        $this->bot->sendMessage($chatId, $text, [
            'reply_markup' => $keyboard
        ]);
    }

    /**
     * Send /help instructions.
     */
    private function sendHelpMessage(int|string $chatId): void {
        $text = "📖 <b>Help & Instructions</b>\n\n" .
            "<b>Supported link formats:</b>\n\n" .
            "<b>Twitter / X:</b>\n" .
            "• <code>https://x.com/username/status/1234567890</code>\n" .
            "• <code>https://twitter.com/username/status/1234567890</code>\n" .
            "• <code>https://mobile.twitter.com/...</code>\n" .
            "• <code>https://fxtwitter.com/...</code> or <code>fixupx.com/...</code>\n\n" .
            "<b>TikTok:</b>\n" .
            "• <code>https://www.tiktok.com/@username/video/1234567890</code>\n" .
            "• <code>https://vm.tiktok.com/xxxxxx/</code> or <code>vt.tiktok.com/xxxxxx/</code>\n" .
            "• <code>https://www.tiktok.com/@username/photo/1234567890</code> (photo slideshows)\n\n" .
            "<b>Features:</b>\n" .
            "• ✨ Automatic HD quality & watermark-free\n" .
            "• 🎵 One-tap background audio / MP3 download\n" .
            "• 🎥 Video and animated GIF support\n" .
            "• ⚡ Fast streaming delivery\n" .
            "• 🆓 Completely free to use\n\n" .
            "<i>Note: Content from private or protected accounts cannot be downloaded.</i>";

        $this->bot->sendMessage($chatId, $text);
    }

    /**
     * Send /about information.
     */
    private function sendAboutMessage(int|string $chatId): void {
        $text = "🤖 <b>Twitter & TikTok Video Downloader Bot</b>\n\n" .
            "• <b>Language:</b> PHP 8.x\n" .
            "• <b>Platform:</b> Telegram Bot API\n" .
            "• <b>Features:</b> Twitter / X & TikTok (Watermark-Free HD + MP3)\n" .
            "• <b>Source:</b> Open Source\n\n" .
            "Built to download public Twitter/X and TikTok media clips fast and effortlessly.";

        $this->bot->sendMessage($chatId, $text);
    }

    /**
     * Handle button callback clicks.
     */
    private function handleCallbackQuery(array $callback): void {
        $id = $callback['id'] ?? '';
        $data = $callback['data'] ?? '';
        $chatId = $callback['message']['chat']['id'] ?? null;

        if (!$chatId) {
            $this->bot->answerCallbackQuery($id);
            return;
        }

        $this->bot->answerCallbackQuery($id);

        if ($data === 'help') {
            $this->sendHelpMessage($chatId);
        } elseif ($data === 'about') {
            $this->sendAboutMessage($chatId);
        } elseif (str_starts_with($data, 'tt_aud:')) {
            $audioId = substr($data, 7);
            $cacheFile = rtrim($this->config['temp_dir'], '/\\') . DIRECTORY_SEPARATOR . "tt_aud_{$audioId}.json";
            if (file_exists($cacheFile)) {
                $audioData = json_decode((string)file_get_contents($cacheFile), true);
                if (!empty($audioData['url'])) {
                    $this->bot->sendChatAction($chatId, 'upload_voice');
                    $this->bot->sendAudio($chatId, $audioData['url'], [
                        'title' => $audioData['title'] ?? 'Original Sound',
                        'performer' => $audioData['author'] ?? 'TikTok',
                        'caption' => "🎵 <b>" . htmlspecialchars($audioData['title'] ?? 'Original Sound', ENT_QUOTES, 'UTF-8') . "</b>\n👤 " . htmlspecialchars($audioData['author'] ?? 'TikTok', ENT_QUOTES, 'UTF-8')
                    ]);
                }
            } else {
                $this->bot->sendMessage($chatId, "⚠️ <i>Audio download link expired. Please re-send the TikTok link to download audio.</i>");
            }
        }
    }
}
