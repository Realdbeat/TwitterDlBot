<?php
/**
 * Telegram Webhook CLI Management Tool
 *
 * Usage:
 *   php set_webhook.php info
 *   php set_webhook.php set https://yourdomain.com/webhook.php
 *   php set_webhook.php delete
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

use TwitterDlBot\TelegramBot;

if (empty($config['bot_token']) || $config['bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
    echo "Error: TELEGRAM_BOT_TOKEN is not configured in .env\n";
    exit(1);
}

$bot = new TelegramBot($config['bot_token']);

$action = $argv[1] ?? 'info';

switch (strtolower($action)) {
    case 'set':
        $url = $argv[2] ?? $config['webhook_url'] ?? '';
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo "Error: Please provide a valid HTTPS URL.\n";
            echo "Usage: php set_webhook.php set https://yourdomain.com/webhook.php\n";
            exit(1);
        }

        $options = [];
        if (!empty($config['webhook_secret'])) {
            $options['secret_token'] = $config['webhook_secret'];
        }

        echo "Setting webhook to: {$url} ...\n";
        $res = $bot->setWebhook($url, $options);
        echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
        break;

    case 'delete':
    case 'del':
        echo "Deleting webhook ...\n";
        $res = $bot->deleteWebhook(true);
        echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
        break;

    case 'info':
    default:
        echo "Getting webhook info ...\n";
        $res = $bot->getWebhookInfo();
        echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
        break;
}
