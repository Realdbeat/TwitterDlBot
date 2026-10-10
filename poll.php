<?php
/**
 * Telegram Twitter Video Downloader Bot
 * Web Polling Runner (Browser & Web Cron)
 *
 * Runs updates processing via HTTP GET / POST without persistent CLI processes.
 * Can be triggered via browser, cPanel curl/wget cron, or free cron services (e.g. cron-job.org).
 */

declare(strict_types=1);

chdir(__DIR__);

require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

use TwitterDlBot\TelegramBot;
use TwitterDlBot\TwitterDownloader;
use TwitterDlBot\TikTokDownloader;
use TwitterDlBot\Database;
use TwitterDlBot\BotHandler;
use TwitterDlBot\Logger;

header('Content-Type: application/json');

if (empty($config['bot_token']) || $config['bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bot token not configured in .env']);
    exit;
}

// Single execution lock to prevent overlapping runs
$lockPath = ($config['temp_dir'] ?? sys_get_temp_dir()) . '/web_poll.lock';
$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => true, 'status' => 'Another polling cycle is active. Exiting cleanly.']);
    exit;
}

register_shutdown_function(function () use ($lockFp) {
    if (is_resource($lockFp)) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
});

try {
    $db = new Database($config['temp_dir']);
    $bot = new TelegramBot($config['bot_token'], 15);
    $downloader = new TwitterDownloader($config['http_timeout']);
    $tiktokDownloader = new TikTokDownloader($config['http_timeout']);
    $handler = new BotHandler($bot, $downloader, $config, $tiktokDownloader, $db);

    // If a webhook is registered, clear it so getUpdates succeeds
    $wh = $bot->getWebhookInfo();
    if (!empty($wh['result']['url'])) {
        $bot->deleteWebhook(true);
        Logger::info('polling', 'Cleared webhook to enable web polling.');
    }

    // Fetch up to 20 pending updates immediately (timeout 0 for fast HTTP response)
    $updates = $bot->getUpdates(0, 20, 0);

    if (!($updates['ok'] ?? false)) {
        echo json_encode(['ok' => false, 'error' => $updates['description'] ?? 'Failed to get updates']);
        exit;
    }

    $items = $updates['result'] ?? [];
    $processed = 0;
    $details = [];

    foreach ($items as $update) {
        $updateId = $update['update_id'];
        $from = $update['message']['from']['username'] 
            ?? $update['message']['from']['first_name'] 
            ?? $update['callback_query']['from']['username'] 
            ?? 'User';
        $text = $update['message']['text'] 
            ?? $update['message']['caption'] 
            ?? $update['callback_query']['data'] 
            ?? '[Media/Other]';

        try {
            $handler->handleUpdate($update);
            $processed++;
            $details[] = [
                'update_id' => $updateId,
                'from' => $from,
                'snippet' => mb_substr($text, 0, 40),
                'status' => 'handled'
            ];
            Logger::success('polling', "WebPoll: Handled update #{$updateId} from @{$from}");
        } catch (\Throwable $e) {
            Logger::error('polling', "WebPoll error #{$updateId}: " . $e->getMessage());
            $details[] = [
                'update_id' => $updateId,
                'error' => $e->getMessage()
            ];
        }

        // Acknowledge update offset so Telegram marks it resolved
        $bot->getUpdates($updateId + 1, 1, 0);
    }

    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'processed_count' => $processed,
        'updates' => $details
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
