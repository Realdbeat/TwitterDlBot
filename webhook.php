<?php
/**
 * Telegram Twitter Video Downloader Bot
 * Webhook Handler (for Web Server / Nginx / Apache / Caddy)
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

use TwitterDlBot\TelegramBot;
use TwitterDlBot\TwitterDownloader;
use TwitterDlBot\BotHandler;

// Immediate response helper
function sendResponse(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hasToken = !empty($config['bot_token']) && $config['bot_token'] !== 'YOUR_TELEGRAM_BOT_TOKEN_HERE';
    sendResponse(200, [
        'ok' => true,
        'service' => 'Twitter Video Downloader Telegram Bot',
        'status' => 'Webhook endpoint is active and waiting for Telegram updates.',
        'bot_token_configured' => $hasToken,
        'hint' => 'Telegram sends updates using HTTP POST. To register this webhook, visit: https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=' . urlencode('https://' . ($_SERVER['HTTP_HOST'] ?? 'xdlbot.mossubyte.com.ng') . '/webhook.php')
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, ['ok' => false, 'error' => 'Method Not Allowed']);
}

// Check secret token if configured
if (!empty($config['webhook_secret'])) {
    $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($incomingSecret !== $config['webhook_secret']) {
        sendResponse(403, ['ok' => false, 'error' => 'Unauthorized']);
    }
}

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    sendResponse(400, ['ok' => false, 'error' => 'Empty request body']);
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    sendResponse(400, ['ok' => false, 'error' => 'Invalid JSON payload']);
}

// Acknowledge Telegram immediately to prevent re-deliveries if processing takes a few seconds
if (function_exists('fastcgi_finish_request')) {
    echo json_encode(['ok' => true]);
    fastcgi_finish_request();
}

try {
    $bot = new TelegramBot($config['bot_token'], $config['http_timeout']);
    $downloader = new TwitterDownloader($config['http_timeout']);
    $handler = new BotHandler($bot, $downloader, $config);

    $handler->handleUpdate($update);
} catch (\Throwable $e) {
    $logFile = $config['temp_dir'] . '/error.log';
    $time = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$time}] Webhook error: {$e->getMessage()}\n", FILE_APPEND);
}

if (!function_exists('fastcgi_finish_request')) {
    sendResponse(200, ['ok' => true]);
}
