<?php
namespace TwitterDlBot;

use CURLFile;

/**
 * Handles incoming Telegram updates and orchestrates responses.
 */
class BotHandler {
    private TelegramBot $bot;
    private TwitterDownloader $downloader;
    private array $config;

    public function __construct(TelegramBot $bot, TwitterDownloader $downloader, array $config) {
        $this->bot = $bot;
        $this->downloader = $downloader;
        $this->config = $config;
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

        // Extract tweet links from text
        $tweetLinks = $this->downloader->extractTweetLinks($text);

        if (empty($tweetLinks)) {
            if ($isPrivate) {
                $this->bot->sendMessage(
                    $chatId,
                    "👋 Please send a valid <b>Twitter / X</b> link.\n\n" .
                    "Example:\n<code>https://x.com/username/status/1234567890</code>\n\n" .
                    "Type /help for instructions.",
                    [
                        'reply_to_message_id' => $message['message_id'] ?? null
                    ]
                );
            }
            return;
        }

        // Limit processing to max 3 links per message to prevent abuse
        $tweetLinks = array_slice($tweetLinks, 0, 3);

        foreach ($tweetLinks as $item) {
            $this->processTweetLink($chatId, $item, $message['message_id'] ?? null);
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
     * Send /start welcome message.
     */
    private function sendStartMessage(int|string $chatId, string $name): void {
        $text = "👋 <b>Hello, " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "!</b>\n\n" .
            "I am your <b>Twitter (X) Video Downloader Bot</b>.\n\n" .
            "🚀 <b>How it works:</b>\n" .
            "1. Copy any video link from Twitter or X\n" .
            "2. Send or paste it in this chat\n" .
            "3. I'll download and send the video right back!\n\n" .
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
            "<b>Supported link formats:</b>\n" .
            "• <code>https://x.com/username/status/1234567890</code>\n" .
            "• <code>https://twitter.com/username/status/1234567890</code>\n" .
            "• <code>https://mobile.twitter.com/...</code>\n" .
            "• <code>https://fxtwitter.com/...</code> or <code>fixupx.com/...</code>\n\n" .
            "<b>Features:</b>\n" .
            "• ✨ Automatic highest-quality resolution\n" .
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
        $text = "🤖 <b>Twitter Video Downloader Bot</b>\n\n" .
            "• <b>Language:</b> PHP 8.x\n" .
            "• <b>Platform:</b> Telegram Bot API\n" .
            "• <b>Source:</b> Open Source\n\n" .
            "Built to download public Twitter/X video clips fast and effortlessly.";

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
        }
    }
}
