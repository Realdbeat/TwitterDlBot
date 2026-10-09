<?php
/**
 * Telegram Twitter Video Downloader Bot
 * Long Polling Daemon
 *
 * Run with: php bot.php
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

use TwitterDlBot\TelegramBot;
use TwitterDlBot\TwitterDownloader;
use TwitterDlBot\BotHandler;

// Set unlimited execution time for CLI daemon
set_time_limit(0);
ini_set('memory_limit', '256M');

function logInfo(string $message): void {
    $time = date('Y-m-d H:i:s');
    echo "[{$time}] [INFO] {$message}\n";
}

function logError(string $message): void {
    $time = date('Y-m-d H:i:s');
    echo "[{$time}] [ERROR] {$message}\n";
}

echo "===============================================\n";
echo "   Twitter Video Downloader Telegram Bot       \n";
echo "===============================================\n\n";

if (empty($config['bot_token']) || $config['bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
    logError("Telegram Bot Token is missing!");
    echo "\nPlease configure your TELEGRAM_BOT_TOKEN in .env file:\n";
    echo "1. Open .env\n";
    echo "2. Set TELEGRAM_BOT_TOKEN=\"your_bot_token_from_botfather\"\n";
    echo "3. Run 'php bot.php' again.\n\n";
    exit(1);
}

try {
    $bot = new TelegramBot($config['bot_token'], $config['http_timeout']);
    $downloader = new TwitterDownloader($config['http_timeout']);
    $handler = new BotHandler($bot, $downloader, $config);

    // Verify token with getMe
    logInfo("Testing connection to Telegram Bot API...");
    $me = $bot->getMe();

    if (!($me['ok'] ?? false)) {
        logError("Failed to connect to Telegram API: " . ($me['description'] ?? 'Unknown error'));
        exit(1);
    }

    $botUser = $me['result']['username'] ?? 'Unknown';
    $botFirstName = $me['result']['first_name'] ?? 'Bot';
    logInfo("Logged in as @{$botUser} ({$botFirstName}) [ID: {$me['result']['id']}]");

    // Remove any webhook so polling works
    logInfo("Removing any existing webhook...");
    $del = $bot->deleteWebhook(false);
    if (!($del['ok'] ?? false)) {
        logError("Warning: Could not clear webhook: " . ($del['description'] ?? ''));
    }

    logInfo("Temporary download folder: " . $config['temp_dir']);
    logInfo("Max upload size limit: " . $config['max_file_size_mb'] . "MB");
    logInfo("Bot is active and polling for updates! Press Ctrl+C to stop.\n");

    $offset = 0;
    $running = true;

    // Register signal handlers for graceful shutdown if pcntl is available
    if (function_exists('pcntl_signal')) {
        declare(ticks=1);
        pcntl_signal(SIGINT, function () use (&$running) {
            logInfo("\nShutdown signal received. Exiting gracefully...");
            $running = false;
        });
        pcntl_signal(SIGTERM, function () use (&$running) {
            logInfo("\nTermination signal received. Exiting gracefully...");
            $running = false;
        });
    }

    while ($running) {
        try {
            $updates = $bot->getUpdates($offset, 100, 25);

            if (!($updates['ok'] ?? false)) {
                $err = $updates['description'] ?? 'Unknown error';
                logError("Failed to fetch updates: {$err}");
                sleep(3);
                continue;
            }

            $items = $updates['result'] ?? [];
            foreach ($items as $update) {
                $updateId = $update['update_id'];
                $offset = $updateId + 1;

                // Log brief info
                if (isset($update['message'])) {
                    $from = $update['message']['from']['username'] ?? $update['message']['from']['first_name'] ?? 'User';
                    $text = $update['message']['text'] ?? '[Media/Other]';
                    $textPreview = mb_substr(str_replace(["\r", "\n"], ' ', $text), 0, 50);
                    logInfo("Message from @{$from}: {$textPreview}");
                } elseif (isset($update['callback_query'])) {
                    $from = $update['callback_query']['from']['username'] ?? 'User';
                    $data = $update['callback_query']['data'] ?? '';
                    logInfo("Callback from @{$from}: {$data}");
                }

                // Handle update
                try {
                    $handler->handleUpdate($update);
                } catch (\Throwable $e) {
                    logError("Error while handling update #{$updateId}: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            logError("Polling loop exception: " . $e->getMessage());
            sleep(3);
        }
    }

    logInfo("Bot stopped successfully.");
} catch (\Throwable $e) {
    logError("Fatal error: " . $e->getMessage());
    exit(1);
}
