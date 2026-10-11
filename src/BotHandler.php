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
    private Database $db;
    private array $config;

    public function __construct(
        TelegramBot $bot,
        TwitterDownloader $downloader,
        array $config,
        ?TikTokDownloader $tiktokDownloader = null,
        ?Database $db = null
    ) {
        $this->bot = $bot;
        $this->downloader = $downloader;
        $this->config = $config;
        $this->tiktokDownloader = $tiktokDownloader ?? new TikTokDownloader($config['http_timeout'] ?? 30);
        $this->db = $db ?? new Database($config['temp_dir'] ?? sys_get_temp_dir());
    }

    /**
     * Handles a single update received from Telegram.
     */
    public function handleUpdate(array $update): void {
        // Handle Pre-Checkout Query (Telegram Stars payment validation)
        if (isset($update['pre_checkout_query'])) {
            $this->handlePreCheckoutQuery($update['pre_checkout_query']);
            return;
        }

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
        $userId = $message['from']['id'] ?? $chatId;
        $fromUser = $message['from']['username'] ?? null;
        $firstName = $message['from']['first_name'] ?? 'Friend';
        $text = trim($message['text'] ?? $message['caption'] ?? '');
        $isPrivate = ($message['chat']['type'] ?? '') === 'private';

        if (!$chatId) {
            return;
        }

        // Keep track of user in database
        if ($userId) {
            $this->db->upsertUser($userId, $chatId, $fromUser, $firstName);
        }

        // Handle successful payment of Telegram Stars
        if (isset($message['successful_payment'])) {
            $this->handleSuccessfulPayment($message);
            return;
        }

        if ($text === '') {
            return;
        }

        // Handle slash commands
        if (str_starts_with($text, '/')) {
            $parts = explode(' ', $text, 2);
            $command = strtolower($parts[0]);
            // Strip @botusername from command
            $command = explode('@', $command)[0];
            $param = trim($parts[1] ?? '');

            switch ($command) {
                case '/start':
                    if ($param !== '' && str_starts_with($param, 'ref_')) {
                        $referrerId = substr($param, 4);
                        $refResult = $this->db->processReferral($userId, $chatId, $referrerId, $fromUser, $firstName);
                        if ($refResult !== null) {
                            $refUntil = date('Y-m-d H:i', $refResult['new_premium_until']);
                            $this->bot->sendMessage(
                                $refResult['referrer_chat_id'],
                                "🎉 <b>New Referral!</b>\n\n" .
                                "A new user joined using your personal invite link!\n" .
                                "🎁 <b>+1 Day of Ad-Free Premium</b> has been added to your account!\n\n" .
                                "⭐ Active Until: <b>{$refUntil} UTC</b>\n" .
                                "👥 Total Referrals: <b>{$refResult['referral_count']}</b>"
                            );

                            $this->sendStartMessage($chatId, $firstName, true);
                            return;
                        }
                    }
                    $this->sendStartMessage($chatId, $firstName);
                    return;

                case '/premium':
                case '/subscribe':
                case '/stars':
                    $this->sendPremiumMenu($chatId, $userId);
                    return;

                case '/ref':
                case '/referral':
                case '/invite':
                    $this->sendReferralMenu($chatId, $userId);
                    return;

                case '/status':
                case '/myplan':
                case '/plan':
                    $this->sendStatusMenu($chatId, $userId);
                    return;

                case '/help':
                    $this->sendHelpMessage($chatId);
                    return;

                case '/about':
                    $this->sendAboutMessage($chatId);
                    return;
            }
        }

        // 1. Check if user sent a TikTok profile page link (e.g., https://www.tiktok.com/@username)
        $profileUsername = $this->tiktokDownloader->extractProfileUsername($text);
        if ($profileUsername !== null) {
            $this->processTikTokProfilePage($chatId, $userId, $profileUsername, $message['message_id'] ?? null);
            return;
        }

        // 2. Extract single tweet and tiktok media links from text
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
                    "• <code>https://www.tiktok.com/@username</code> (Download creator's videos)\n" .
                    "• <code>https://vm.tiktok.com/ZMxxxxxx/</code>\n\n" .
                    "Type /help for instructions, /premium for ad-free plans, or /ref to earn free days!",
                    [
                        'reply_to_message_id' => $message['message_id'] ?? null
                    ]
                );
            }
            return;
        }

        // Limit processing to max 3 links each to prevent abuse
        $isPremium = $this->db->isUserPremium($userId);

        if (!empty($tweetLinks)) {
            $tweetLinks = array_slice($tweetLinks, 0, 3);
            foreach ($tweetLinks as $item) {
                if ($isPremium) {
                    $this->processTweetLink($chatId, $item, $message['message_id'] ?? null);
                } elseif ($this->isAdEnabled()) {
                    $this->queueTweetForAd($chatId, $userId, $item, $message['message_id'] ?? null);
                } else {
                    $this->processTweetLink($chatId, $item, $message['message_id'] ?? null);
                }
            }
        }

        if (!empty($tiktokLinks)) {
            $tiktokLinks = array_slice($tiktokLinks, 0, 3);
            foreach ($tiktokLinks as $item) {
                if ($isPremium) {
                    $this->processTikTokLink($chatId, $item, $message['message_id'] ?? null);
                } elseif ($this->isAdEnabled()) {
                    $this->queueTikTokForAd($chatId, $userId, $item, $message['message_id'] ?? null);
                } else {
                    $this->processTikTokLink($chatId, $item, $message['message_id'] ?? null);
                }
            }
        }
    }

    /**
     * Process a single tweet link.
     */
    public function processTweetLink(int|string $chatId, array $item, ?int $replyToId): void {
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
    public function processTikTokLink(int|string $chatId, array $item, ?int $replyToId): void {
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
    private function sendStartMessage(int|string $chatId, string $name, bool $wasReferred = false): void {
        $prefix = $wasReferred 
            ? "🎁 <b>You were invited by a friend!</b> +1 Day of Ad-Free Premium was credited to their account!\n\n"
            : "";

        $text = "{$prefix}👋 <b>Hello, " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "!</b>\n\n" .
            "I am your <b>Twitter (X) & TikTok Video Downloader Bot</b>.\n\n" .
            "🚀 <b>Features:</b>\n" .
            "• 🎬 Watermark-free HD video downloads\n" .
            "• 🎵 Instant MP3 audio extraction\n" .
            "• 📥 TikTok creator profile bulk downloading\n" .
            "• ⭐ <b>Ad-Free Downloads</b> via Telegram Stars (/premium)\n" .
            "• 🎁 <b>Free Premium Days:</b> Earn 1 day of ad-free downloads per friend invited (/ref)!\n\n" .
            "💡 <i>Send any Twitter or TikTok link now to download!</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⭐ Premium (Ad-Free)', 'callback_data' => 'show_plans'],
                    ['text' => '🎁 Earn Free Premium', 'callback_data' => 'show_ref']
                ],
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
            "• <code>https://twitter.com/username/status/1234567890</code>\n\n" .
            "<b>TikTok:</b>\n" .
            "• <code>https://www.tiktok.com/@username/video/1234567890</code>\n" .
            "• <code>https://vm.tiktok.com/xxxxxx/</code> or <code>vt.tiktok.com/xxxxxx/</code>\n" .
            "• <code>https://www.tiktok.com/@username/photo/1234567890</code> (photo slideshows)\n" .
            "• <code>https://www.tiktok.com/@username</code> (Creator profile: download all videos)\n\n" .
            "<b>⭐ Subscriptions & Referrals:</b>\n" .
            "• <b>/premium</b>: Upgrade with Telegram Stars for ad-free downloads\n" .
            "  - 7 Days: ⭐ 100 Stars\n" .
            "  - 1 Month: ⭐ 350 Stars\n" .
            "  - 1 Year: ⭐ 2,000 Stars\n" .
            "• <b>/ref</b>: Share your personal referral link and get <b>+1 Day of Free Premium</b> for each invited friend!\n" .
            "• <b>/status</b>: View your active subscription expiration and referral stats.\n\n" .
            "<i>Note: Content from private or protected accounts cannot be downloaded.</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⭐ View Premium Plans', 'callback_data' => 'show_plans'],
                    ['text' => '🎁 Referral Program', 'callback_data' => 'show_ref']
                ]
            ]
        ];

        $this->bot->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    /**
     * Send /about information.
     */
    private function sendAboutMessage(int|string $chatId): void {
        $text = "🤖 <b>Twitter & TikTok Video Downloader Bot</b>\n\n" .
            "• <b>Language:</b> PHP 8.x\n" .
            "• <b>Platform:</b> Telegram Bot API\n" .
            "• <b>Features:</b> Twitter / X & TikTok (Watermark-Free HD + MP3)\n" .
            "• <b>Profile Downloader:</b> Bulk download 10 videos at a time from creator pages\n" .
            "• <b>Storage:</b> SQLite Session & Queue Management\n" .
            "• <b>Source:</b> Open Source\n\n" .
            "Built to download public Twitter/X and TikTok media clips fast and effortlessly.";

        $this->bot->sendMessage($chatId, $text);
    }

    /**
     * Process TikTok profile page request:
     * 1. Fetches creator metadata (nickname, video count, followers).
     * 2. Queues the first batch of videos in SQLite.
     * 3. Sends confirmation prompt asking for user permission to start download.
     */
    private function processTikTokProfilePage(int|string $chatId, int|string $userId, string $username, ?int $replyToId): void {
        $statusMsg = $this->bot->sendMessage(
            $chatId,
            "🔍 <i>Analyzing TikTok profile @{$username}...</i>",
            ['reply_to_message_id' => $replyToId]
        );
        $statusMsgId = $statusMsg['result']['message_id'] ?? null;

        $this->bot->sendChatAction($chatId, 'typing');

        $profileInfo = $this->tiktokDownloader->getUserProfileInfo($username);

        if ($profileInfo === null) {
            $err = "❌ <b>Could not find TikTok profile @{$username}!</b>\n\nPlease verify the username and try again.";
            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, $err);
            } else {
                $this->bot->sendMessage($chatId, $err, ['reply_to_message_id' => $replyToId]);
            }
            return;
        }

        if ($profileInfo['video_count'] <= 0) {
            $err = "ℹ️ <b>No public videos found!</b>\n\nProfile <b>@{$username}</b> has 0 videos or the account is private.";
            if ($statusMsgId) {
                $this->bot->editMessageText($chatId, $statusMsgId, $err);
            } else {
                $this->bot->sendMessage($chatId, $err, ['reply_to_message_id' => $replyToId]);
            }
            return;
        }

        // Fetch initial batch of videos to queue
        $batch = $this->tiktokDownloader->fetchUserVideos($username);
        $sessionKey = 'tts_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16);

        $sessionId = $this->db->createSession(
            $sessionKey,
            $chatId,
            $userId,
            $profileInfo['username'],
            $profileInfo['nickname'],
            $profileInfo['video_count'],
            $batch['pagination_state'],
            $batch['has_more']
        );

        if (!empty($batch['videos'])) {
            $this->db->addVideosToSession($sessionId, $batch['videos']);
        }

        $nickname = htmlspecialchars($profileInfo['nickname'], ENT_QUOTES, 'UTF-8');
        $uname = htmlspecialchars($profileInfo['username'], ENT_QUOTES, 'UTF-8');
        $totalVideos = number_format($profileInfo['video_count']);
        $followers = number_format($profileInfo['follower_count']);
        $followerLine = ($profileInfo['follower_count'] > 0) ? "\n• 👥 <b>Followers:</b> {$followers}" : "";

        $text = "👤 <b>TikTok Profile Found</b>\n\n" .
            "• 🎬 <b>Creator:</b> {$nickname} (@{$uname})\n" .
            "• 📹 <b>Total Videos:</b> {$totalVideos}{$followerLine}\n\n" .
            "⚠️ <b>Download Permission:</b>\n" .
            "Do you want to start downloading videos from this profile?\n" .
            "Videos will be downloaded in batches of <b>10 at a time</b> with a <b>Next</b> button between batches.\n\n" .
            "<i>Click below to confirm and begin downloading the first 10 videos.</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '▶️ Start Download (First 10)', 'callback_data' => "tts_start:{$sessionKey}"]
                ],
                [
                    ['text' => '❌ Cancel', 'callback_data' => "tts_cancel:{$sessionKey}"]
                ]
            ]
        ];

        if ($statusMsgId) {
            $this->bot->editMessageText($chatId, $statusMsgId, $text, ['reply_markup' => $keyboard]);
        } else {
            $this->bot->sendMessage($chatId, $text, [
                'reply_to_message_id' => $replyToId,
                'reply_markup' => $keyboard
            ]);
        }
    }

    /**
     * Executes a batch download of 10 videos for a profile session.
     */
    private function runBatchDownload(int|string $chatId, string $sessionKey): void {
        $session = $this->db->getSession($sessionKey);
        if (!$session) {
            $this->bot->sendMessage($chatId, "⚠️ <i>Download session not found or expired.</i>");
            return;
        }

        if (in_array($session['status'], ['stopped', 'cancelled'])) {
            $this->bot->sendMessage($chatId, "ℹ️ <i>This download session was stopped. Send the profile link again to restart.</i>");
            return;
        }

        $this->db->updateSessionStatus($sessionKey, 'in_progress');

        // Check if we have at least 10 pending videos in SQLite; if not and has_more is true, fetch next page
        $pending = $this->db->getPendingVideos((int)$session['id'], 10);
        if (count($pending) < 10 && $session['has_more'] && !empty($session['pagination_state_array'])) {
            $nextBatch = $this->tiktokDownloader->fetchUserVideos($session['username'], $session['pagination_state_array']);
            if (!empty($nextBatch['videos'])) {
                $this->db->addVideosToSession((int)$session['id'], $nextBatch['videos']);
            }
            $this->db->updateSessionPagination($sessionKey, $nextBatch['pagination_state'], $nextBatch['has_more']);
            $session = $this->db->getSession($sessionKey);
            $pending = $this->db->getPendingVideos((int)$session['id'], 10);
        }

        if (empty($pending)) {
            $totalDownloaded = $this->db->countDownloadedVideos((int)$session['id']);
            $this->db->updateSessionStatus($sessionKey, 'completed');
            $this->bot->sendMessage(
                $chatId,
                "🎉 <b>All available videos have been downloaded!</b>\n\n" .
                "Downloaded a total of <b>{$totalDownloaded} videos</b> from <b>@{$session['username']}</b>."
            );
            return;
        }

        $batchCount = count($pending);
        $startNum = $session['downloaded_count'] + 1;
        $endNum = $session['downloaded_count'] + $batchCount;

        $progressMsg = $this->bot->sendMessage(
            $chatId,
            "⏳ <b>Downloading batch ({$startNum} to {$endNum} of " . number_format($session['total_videos']) . ")...</b>\n" .
            "<i>Sending videos to chat now...</i>"
        );
        $progressMsgId = $progressMsg['result']['message_id'] ?? null;

        $sentInBatch = 0;
        foreach ($pending as $videoRow) {
            $this->bot->sendChatAction($chatId, 'upload_video');

            $videoInfo = $this->tiktokDownloader->getVideoInfo($videoRow['url'], $videoRow['video_id'], $session['username']);

            if (!$videoInfo || empty($videoInfo['videos'])) {
                $this->db->markVideoFailed((int)$videoRow['id']);
                continue;
            }

            $currentNum = $session['downloaded_count'] + $sentInBatch + 1;
            $video = $videoInfo['videos'][0];
            $videoUrl = $video['url'];

            $title = !empty($videoInfo['text']) ? $videoInfo['text'] : $videoRow['title'];
            $cleanTitle = htmlspecialchars(trim($title), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($cleanTitle) > 180) {
                $cleanTitle = mb_substr($cleanTitle, 0, 177) . '...';
            }

            $caption = "📹 <b>Video #{$currentNum} of " . number_format($session['total_videos']) . "</b>\n" .
                "🎬 <i>\"{$cleanTitle}\"</i>\n" .
                "👤 @{$session['username']}\n\n" .
                "🔗 <a href=\"{$videoRow['url']}\">View on TikTok</a>";

            $videoOptions = [
                'caption' => $caption,
                'duration' => !empty($video['duration']) ? (int)$video['duration'] : null,
                'width' => !empty($video['width']) ? (int)$video['width'] : null,
                'height' => !empty($video['height']) ? (int)$video['height'] : null
            ];

            // 1. Direct URL send
            $res = $this->bot->sendVideo($chatId, $videoUrl, $videoOptions);
            $success = $res['ok'] ?? false;

            // 2. Local buffer fallback if Telegram rejects URL
            if (!$success) {
                $tempFile = $this->tiktokDownloader->downloadVideoToTemp(
                    $videoUrl,
                    $this->config['temp_dir'],
                    $this->config['max_file_size_mb']
                );
                if ($tempFile && file_exists($tempFile)) {
                    $curlFile = new CURLFile($tempFile, 'video/mp4', 'tiktok_video.mp4');
                    $uploadRes = $this->bot->sendVideo($chatId, $curlFile, $videoOptions);
                    if (!($uploadRes['ok'] ?? false)) {
                        $uploadRes = $this->bot->sendDocument($chatId, $curlFile, $videoOptions);
                    }
                    @unlink($tempFile);
                    $success = $uploadRes['ok'] ?? false;
                }
            }

            if ($success) {
                $this->db->markVideoDownloaded((int)$videoRow['id']);
                $sentInBatch++;
                $this->db->incrementSessionDownloaded($sessionKey, 1);
            } else {
                $this->db->markVideoFailed((int)$videoRow['id']);
            }

            // Sleep 1 second between videos to respect Telegram rate limits
            sleep(1);
        }

        // Clean up progress message
        if ($progressMsgId) {
            $this->bot->deleteMessage($chatId, $progressMsgId);
        }

        // Refresh session
        $session = $this->db->getSession($sessionKey);
        $totalDownloaded = $this->db->countDownloadedVideos((int)$session['id']);
        $pendingLeft = $this->db->countPendingVideos((int)$session['id']);
        $hasMore = $session['has_more'] || ($pendingLeft > 0);

        if ($hasMore) {
            $summaryText = "✅ <b>Batch Completed!</b>\n\n" .
                "• 📥 <b>Sent in this batch:</b> {$sentInBatch} videos\n" .
                "• 📊 <b>Total downloaded so far:</b> {$totalDownloaded} of " . number_format($session['total_videos']) . "\n\n" .
                "Would you like to download the next 10 videos?";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⏩ Download Next 10', 'callback_data' => "tts_next:{$sessionKey}"]
                    ],
                    [
                        ['text' => '⏹️ Stop Download', 'callback_data' => "tts_stop:{$sessionKey}"]
                    ]
                ]
            ];

            $this->bot->sendMessage($chatId, $summaryText, ['reply_markup' => $keyboard]);
        } else {
            $this->db->updateSessionStatus($sessionKey, 'completed');
            $this->bot->sendMessage(
                $chatId,
                "🎉 <b>Download Complete!</b>\n\n" .
                "All available videos from <b>@{$session['username']}</b> have been downloaded!\n" .
                "Total: <b>{$totalDownloaded} videos</b>."
            );
        }
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

        $userId = $callback['from']['id'] ?? $chatId;

        if ($data === 'help') {
            $this->sendHelpMessage($chatId);
        } elseif ($data === 'about') {
            $this->sendAboutMessage($chatId);
        } elseif ($data === 'show_plans') {
            $this->sendPremiumMenu($chatId, $userId);
        } elseif ($data === 'show_ref') {
            $this->sendReferralMenu($chatId, $userId);
        } elseif ($data === 'status') {
            $this->sendStatusMenu($chatId, $userId);
        } elseif ($data === 'buy_sub:7d') {
            $this->sendStarsInvoice($chatId, '7d');
        } elseif ($data === 'buy_sub:30d') {
            $this->sendStarsInvoice($chatId, '30d');
        } elseif ($data === 'buy_sub:365d') {
            $this->sendStarsInvoice($chatId, '365d');
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
        } elseif (str_starts_with($data, 'tts_start:')) {
            $sessionKey = substr($data, 10);
            $this->runBatchDownload($chatId, $sessionKey);
        } elseif (str_starts_with($data, 'tts_next:')) {
            $sessionKey = substr($data, 9);
            $this->runBatchDownload($chatId, $sessionKey);
        } elseif (str_starts_with($data, 'tts_stop:')) {
            $sessionKey = substr($data, 9);
            $this->db->updateSessionStatus($sessionKey, 'stopped');
            $session = $this->db->getSession($sessionKey);
            $cnt = $session['downloaded_count'] ?? 0;
            $uname = $session['username'] ?? 'creator';
            $this->bot->sendMessage($chatId, "⏹️ <b>Download stopped.</b> Total videos downloaded: <b>{$cnt}</b> from @{$uname}.");
        } elseif (str_starts_with($data, 'tts_cancel:')) {
            $sessionKey = substr($data, 11);
            $this->db->updateSessionStatus($sessionKey, 'cancelled');
            if (isset($callback['message']['message_id'])) {
                $this->bot->editMessageText($chatId, $callback['message']['message_id'], "❌ <i>Download cancelled.</i>");
            }
        }
    }

    /**
     * Send Telegram Stars invoice for a premium subscription.
     */
    public function sendStarsInvoice(int|string $chatId, string $planKey): void {
        $plans = [
            '7d' => [
                'title' => '⭐ 7 Days Ad-Free Premium',
                'description' => 'Unlock 7 days of instant Twitter/X and TikTok video downloads with zero ads!',
                'payload' => 'sub_7d',
                'amount' => 100
            ],
            '30d' => [
                'title' => '⭐ 1 Month Ad-Free Premium',
                'description' => 'Unlock 30 days of instant Twitter/X and TikTok video downloads with zero ads!',
                'payload' => 'sub_30d',
                'amount' => 350
            ],
            '365d' => [
                'title' => '⭐ 1 Year Ad-Free Premium',
                'description' => 'Unlock 365 days of instant Twitter/X and TikTok video downloads with zero ads!',
                'payload' => 'sub_365d',
                'amount' => 2000
            ]
        ];

        $plan = $plans[$planKey] ?? null;
        if (!$plan) {
            return;
        }

        $this->bot->sendInvoice(
            $chatId,
            $plan['title'],
            $plan['description'],
            $plan['payload'],
            'XTR',
            [
                ['label' => $plan['title'], 'amount' => $plan['amount']]
            ]
        );
    }

    /**
     * Respond to a pre-checkout query before charging Telegram Stars.
     */
    public function handlePreCheckoutQuery(array $query): void {
        $id = $query['id'] ?? '';
        $currency = $query['currency'] ?? '';
        $payload = $query['invoice_payload'] ?? '';

        Logger::info('payment', "Pre-checkout query received for payload: {$payload}", [
            'query_id' => $id,
            'currency' => $currency
        ]);

        if ($currency === 'XTR' && in_array($payload, ['sub_7d', 'sub_30d', 'sub_365d'], true)) {
            $this->bot->answerPreCheckoutQuery($id, true);
        } else {
            $this->bot->answerPreCheckoutQuery($id, false, "Invalid invoice configuration.");
        }
    }

    /**
     * Handles successful Telegram Stars payment.
     */
    public function handleSuccessfulPayment(array $message): void {
        $payment = $message['successful_payment'] ?? [];
        $chatId = $message['chat']['id'] ?? '';
        $userId = $message['from']['id'] ?? $chatId;
        $payload = $payment['invoice_payload'] ?? '';
        $totalAmount = (int)($payment['total_amount'] ?? 0);
        $currency = $payment['currency'] ?? 'XTR';
        $chargeId = $payment['telegram_payment_charge_id'] ?? '';
        $providerChargeId = $payment['provider_payment_charge_id'] ?? '';

        $days = match ($payload) {
            'sub_7d' => 7,
            'sub_30d' => 30,
            'sub_365d' => 365,
            default => 0
        };

        if ($days <= 0) {
            Logger::error('payment', "Unknown invoice payload received: {$payload}", ['payment' => $payment]);
            return;
        }

        $newUntil = $this->db->recordPayment(
            $userId,
            $chatId,
            $chargeId,
            $providerChargeId,
            $payload,
            $currency,
            $totalAmount,
            $days
        );

        $formattedDate = date('Y-m-d H:i', $newUntil);

        Logger::success('payment', "Successful Stars payment by user {$userId}: {$totalAmount} {$currency} for {$days} days", [
            'new_until' => $newUntil
        ]);

        $text = "🎉 <b>Payment Successful! Premium Activated!</b>\n\n" .
                "Thank you for your purchase of <b>⭐ {$totalAmount} Telegram Stars</b>!\n\n" .
                "✨ <b>Your Premium Benefits:</b>\n" .
                "• 🚫 <b>Zero Ads:</b> All video downloads will be processed instantly without ads\n" .
                "• ⚡ <b>Top Speed:</b> Instant media streaming directly to chat\n" .
                "• 📅 <b>Active Until:</b> <b>{$formattedDate} UTC</b>\n\n" .
                "🚀 <i>Send any Twitter / X or TikTok link now to enjoy ad-free downloads!</i>";

        $this->bot->sendMessage($chatId, $text);
    }

    /**
     * Send Premium subscription plans menu.
     */
    public function sendPremiumMenu(int|string $chatId, int|string $userId): void {
        $isPrem = $this->db->isUserPremium($userId);
        $user = $this->db->getUser($userId);
        $premUntil = !empty($user['premium_until']) ? (int)$user['premium_until'] : 0;

        $statusText = $isPrem 
            ? "✅ <b>Active until:</b> " . date('Y-m-d H:i', $premUntil) . " UTC"
            : "❌ <b>Status:</b> Free Tier (Ads enabled)";

        $text = "⭐ <b>Telegram Premium Subscription (Ad-Free Downloads)</b>\n\n" .
                "Upgrade your account with <b>Telegram Stars</b> to download Twitter/X and TikTok videos instantly with <b>zero ads</b>!\n\n" .
                "📊 <b>Your Current Plan:</b>\n" .
                "{$statusText}\n\n" .
                "🌟 <b>Available Subscription Plans:</b>\n" .
                "• <b>7 Days:</b> ⭐ 100 Stars\n" .
                "• <b>1 Month (30 Days):</b> ⭐ 350 Stars\n" .
                "• <b>1 Year (365 Days):</b> ⭐ 2,000 Stars\n\n" .
                "💡 <i>Tap a button below to choose a plan and pay with Telegram Stars:</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⭐ 7 Days (100 Stars)', 'callback_data' => 'buy_sub:7d']
                ],
                [
                    ['text' => '⭐ 1 Month (350 Stars)', 'callback_data' => 'buy_sub:30d']
                ],
                [
                    ['text' => '⭐ 1 Year (2,000 Stars)', 'callback_data' => 'buy_sub:365d']
                ],
                [
                    ['text' => '🎁 Earn Free Premium (Referrals)', 'callback_data' => 'show_ref']
                ]
            ]
        ];

        $this->bot->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    /**
     * Send Referral menu with unique invite link.
     */
    public function sendReferralMenu(int|string $chatId, int|string $userId): void {
        $user = $this->db->getUser($userId);
        $referralCount = (int)($user['referral_count'] ?? 0);
        $isPrem = $this->db->isUserPremium($userId);
        $premUntil = !empty($user['premium_until']) ? (int)$user['premium_until'] : 0;

        $me = $this->bot->getMe();
        $botUsername = $me['result']['username'] ?? 'TwitterDlBot';
        $refLink = "https://t.me/{$botUsername}?start=ref_{$userId}";

        $statusLine = $isPrem 
            ? "⭐ <b>Premium Active Until:</b> " . date('Y-m-d H:i', $premUntil) . " UTC"
            : "ℹ️ <b>Premium Status:</b> Inactive (Invite friends to get free days!)";

        $text = "👥 <b>Referral Program — Earn Free Premium!</b>\n\n" .
                "Invite your friends to use this bot and get <b>+1 Day of Ad-Free Premium</b> for <b>EVERY</b> friend who joins!\n\n" .
                "🎁 <b>How it works:</b>\n" .
                "• 1 Friend = +1 Day Ad-Free\n" .
                "• 10 Friends = +10 Days Ad-Free\n" .
                "• 100 Friends = +100 Days Ad-Free!\n\n" .
                "📊 <b>Your Statistics:</b>\n" .
                "• Total Friends Invited: <b>{$referralCount}</b>\n" .
                "• {$statusLine}\n\n" .
                "🔗 <b>Your Personal Invite Link:</b>\n" .
                "<code>{$refLink}</code>\n\n" .
                "👇 <i>Share your link with friends or groups to start earning free premium:</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📤 Share Referral Link', 'url' => "https://t.me/share/url?url=" . urlencode($refLink) . "&text=" . urlencode("🎬 Fast Twitter & TikTok Video Downloader Bot without watermark!")]
                ],
                [
                    ['text' => '⭐ View Premium Plans', 'callback_data' => 'show_plans']
                ]
            ]
        ];

        $this->bot->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    /**
     * Send Status menu showing plan expiration and stats.
     */
    public function sendStatusMenu(int|string $chatId, int|string $userId): void {
        $user = $this->db->getUser($userId);
        $referralCount = (int)($user['referral_count'] ?? 0);
        $isPrem = $this->db->isUserPremium($userId);
        $premUntil = !empty($user['premium_until']) ? (int)$user['premium_until'] : 0;

        if ($isPrem) {
            $formattedDate = date('Y-m-d H:i:s', $premUntil);
            $remainingHours = round(($premUntil - time()) / 3600, 1);
            $text = "⭐ <b>Your Subscription Status: Active Premium</b>\n\n" .
                    "• <b>Status:</b> Premium (Ad-Free Active)\n" .
                    "• <b>Expires:</b> {$formattedDate} UTC ({$remainingHours}h remaining)\n" .
                    "• <b>Total Referrals:</b> {$referralCount} users invited\n\n" .
                    "All your Twitter / X and TikTok video downloads will download instantly without ads!";
        } else {
            $text = "ℹ️ <b>Your Subscription Status: Free Tier</b>\n\n" .
                    "• <b>Status:</b> Free (Sponsored ads enabled)\n" .
                    "• <b>Total Referrals:</b> {$referralCount} users invited\n\n" .
                    "💡 <i>Tip: Want ad-free downloads? Upgrade via Telegram Stars (/premium) or invite friends to get free days (/ref)!</i>";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⭐ Subscription Plans', 'callback_data' => 'show_plans'],
                    ['text' => '🎁 Referral Program', 'callback_data' => 'show_ref']
                ]
            ]
        ];

        $this->bot->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    /**
     * Checks if Monetag Rewarded Interstitial ad requirement is active.
     */
    public function isAdEnabled(): bool {
        return !empty($this->config['monetag_enabled']) && !empty($this->config['monetag_zone_id']);
    }

    /**
     * Resolves the base URL for the Telegram Mini App.
     */
    public function getMiniAppBaseUrl(): string {
        if (!empty($this->config['mini_app_url'])) {
            return rtrim($this->config['mini_app_url'], '/');
        }

        if (!empty($this->config['webhook_url'])) {
            $parsed = parse_url($this->config['webhook_url']);
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                $base = $parsed['scheme'] . '://' . $parsed['host'];
                if (!empty($parsed['port'])) {
                    $base .= ':' . $parsed['port'];
                }
                return $base;
            }
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            return $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        return '';
    }

    /**
     * Queues a tweet link to require watching a Monetag ad in TMA before delivering the video.
     */
    private function queueTweetForAd(int|string $chatId, int|string $userId, array $item, ?int $replyToId): void {
        $token = bin2hex(random_bytes(16));
        $this->db->createPendingAdDownload($token, $chatId, $userId, 'tweet', $item, $replyToId);

        $baseUrl = $this->getMiniAppBaseUrl();
        $adUrl = $baseUrl . '/ad.php?token=' . $token;

        $authorExcerpt = !empty($item['user']) ? " from @{$item['user']}" : "";
        $text = "🎬 <b>Video Ready to Download!</b>{$authorExcerpt}\n\n" .
                "To support our free bot and unlock your video, please watch a quick sponsored ad.\n\n" .
                "💡 <i>Don't want ads? Upgrade to ⭐ Premium (/premium) or invite friends for free days (/ref)!</i>\n\n" .
                "👇 <b>Tap the button below to watch the ad & get your video:</b>";

        $isHttps = str_starts_with($baseUrl, 'https://');
        $watchButton = [
            'text' => '🎁 Watch Ad to Unlock Video 🎬',
        ];
        if ($isHttps) {
            $watchButton['web_app'] = ['url' => $adUrl];
        } else {
            $watchButton['url'] = $adUrl;
        }

        $keyboard = [
            'inline_keyboard' => [
                [$watchButton],
                [
                    ['text' => '⭐ Skip Ads with Premium', 'callback_data' => 'show_plans']
                ],
                [
                    ['text' => '🌐 Open on X', 'url' => $item['url']]
                ]
            ]
        ];

        $res = $this->bot->sendMessage($chatId, $text, [
            'reply_to_message_id' => $replyToId,
            'reply_markup' => $keyboard
        ]);

        if (!empty($res['result']['message_id'])) {
            $this->db->updatePendingAdStatusMessage($token, (int)$res['result']['message_id']);
        }
    }

    /**
     * Queues a TikTok link to require watching a Monetag ad in TMA before delivering the video.
     */
    private function queueTikTokForAd(int|string $chatId, int|string $userId, array $item, ?int $replyToId): void {
        $token = bin2hex(random_bytes(16));
        $this->db->createPendingAdDownload($token, $chatId, $userId, 'tiktok', $item, $replyToId);

        $baseUrl = $this->getMiniAppBaseUrl();
        $adUrl = $baseUrl . '/ad.php?token=' . $token;

        $userExcerpt = !empty($item['user']) ? " from @{$item['user']}" : "";
        $text = "🎬 <b>TikTok Video Ready!</b>{$userExcerpt}\n\n" .
                "To support our free bot and unlock your watermark-free video, please watch a quick sponsored ad.\n\n" .
                "💡 <i>Don't want ads? Upgrade to ⭐ Premium (/premium) or invite friends for free days (/ref)!</i>\n\n" .
                "👇 <b>Tap the button below to watch the ad & get your video:</b>";

        $isHttps = str_starts_with($baseUrl, 'https://');
        $watchButton = [
            'text' => '🎁 Watch Ad to Unlock Video 🎬',
        ];
        if ($isHttps) {
            $watchButton['web_app'] = ['url' => $adUrl];
        } else {
            $watchButton['url'] = $adUrl;
        }

        $keyboard = [
            'inline_keyboard' => [
                [$watchButton],
                [
                    ['text' => '⭐ Skip Ads with Premium', 'callback_data' => 'show_plans']
                ],
                [
                    ['text' => '🌐 Open on TikTok', 'url' => $item['url']]
                ]
            ]
        ];

        $res = $this->bot->sendMessage($chatId, $text, [
            'reply_to_message_id' => $replyToId,
            'reply_markup' => $keyboard
        ]);

        if (!empty($res['result']['message_id'])) {
            $this->db->updatePendingAdStatusMessage($token, (int)$res['result']['message_id']);
        }
    }

    /**
     * Completes ad verification and delivers the requested video to the user's Telegram chat.
     */
    public function deliverPendingAdDownload(string $token): array {
        $pending = $this->db->getPendingAdDownload($token);
        if (!$pending) {
            return ['ok' => false, 'error' => 'Invalid or expired download token'];
        }

        if ($pending['status'] === 'completed') {
            return ['ok' => true, 'already_completed' => true, 'message' => 'Video has already been delivered!'];
        }

        if ($pending['status'] === 'processing') {
            return ['ok' => true, 'processing' => true, 'message' => 'Video is currently being downloaded and delivered...'];
        }

        // Prevent concurrent double-deliveries
        $this->db->updatePendingAdStatus($token, 'processing');

        $chatId = $pending['chat_id'];
        $replyToId = !empty($pending['reply_to_message_id']) ? (int)$pending['reply_to_message_id'] : null;
        $linkData = $pending['link_data_array'];
        $linkType = $pending['link_type'];
        $statusMsgId = !empty($pending['status_message_id']) ? (int)$pending['status_message_id'] : null;

        // If prompt message exists, update it to show progress
        if ($statusMsgId) {
            $this->bot->editMessageText($chatId, $statusMsgId, "🎉 <b>Ad watched!</b> Unlocking and sending your video now... ⏳");
        }

        try {
            if ($linkType === 'tweet') {
                $this->processTweetLink($chatId, $linkData, $replyToId);
            } elseif ($linkType === 'tiktok') {
                $this->processTikTokLink($chatId, $linkData, $replyToId);
            } else {
                $this->db->updatePendingAdStatus($token, 'failed');
                return ['ok' => false, 'error' => 'Unsupported link type'];
            }

            $this->db->updatePendingAdStatus($token, 'completed');

            // Delete the prompt message to keep chat history tidy
            if ($statusMsgId) {
                $this->bot->deleteMessage($chatId, $statusMsgId);
            }

            Logger::success('monetag_ad', "Delivered video for token {$token} to chat {$chatId}");

            return ['ok' => true, 'message' => 'Video successfully delivered to Telegram chat!'];
        } catch (\Throwable $e) {
            $this->db->updatePendingAdStatus($token, 'failed');
            Logger::error('monetag_ad', "Error delivering video for token {$token}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'pending' => $pending
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
