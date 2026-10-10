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
use TwitterDlBot\TikTokDownloader;
use TwitterDlBot\Database;
use TwitterDlBot\BotHandler;
use TwitterDlBot\Logger;

// Immediate response helper
function sendResponse(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hasToken = !empty($config['bot_token']) && $config['bot_token'] !== 'YOUR_TELEGRAM_BOT_TOKEN_HERE';
    
    Logger::info('webhook', 'Health check GET request received', [
        'ip' => Logger::getClientIp(),
        'user_agent' => Logger::getUserAgent(),
        'query' => $_GET
    ]);

    sendResponse(200, [
        'ok' => true,
        'service' => 'Twitter & TikTok Video Downloader Telegram Bot',
        'status' => 'Webhook endpoint is active and waiting for Telegram updates.',
        'bot_token_configured' => $hasToken,
        'client_ip' => Logger::getClientIp(),
        'user_agent' => Logger::getUserAgent(),
        'hint' => 'Telegram sends updates using HTTP POST. Visit log.php to view live requests and errors.'
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Logger::warning('security', 'Rejected non-POST request to webhook', [
        'method' => $_SERVER['REQUEST_METHOD'],
        'ip' => Logger::getClientIp(),
        'user_agent' => Logger::getUserAgent()
    ]);
    sendResponse(405, ['ok' => false, 'error' => 'Method Not Allowed']);
}

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    Logger::warning('webhook', 'Received empty request body', [
        'ip' => Logger::getClientIp(),
        'user_agent' => Logger::getUserAgent()
    ]);
    sendResponse(400, ['ok' => false, 'error' => 'Empty request body']);
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    Logger::error('webhook', 'Invalid JSON payload received', [
        'raw_input_snippet' => mb_substr($rawInput, 0, 500),
        'ip' => Logger::getClientIp(),
        'user_agent' => Logger::getUserAgent()
    ]);
    sendResponse(400, ['ok' => false, 'error' => 'Invalid JSON payload']);
}

$updateId = $update['update_id'] ?? null;
$fromUser = $update['message']['from']['username'] 
    ?? $update['message']['from']['first_name'] 
    ?? $update['callback_query']['from']['username'] 
    ?? 'Unknown';
$textPreview = $update['message']['text'] 
    ?? $update['message']['caption'] 
    ?? $update['callback_query']['data'] 
    ?? '[Media/Other]';

Logger::info('webhook', "Incoming update #{$updateId} from @{$fromUser}: {$textPreview}", [
    'update_id' => $updateId,
    'from' => $fromUser,
    'payload' => $update
]);

try {
    $db = new Database($config['temp_dir']);
    $bot = new TelegramBot($config['bot_token'], $config['http_timeout']);
    $downloader = new TwitterDownloader($config['http_timeout']);
    $tiktokDownloader = new TikTokDownloader($config['http_timeout']);
    $handler = new BotHandler($bot, $downloader, $config, $tiktokDownloader, $db);

    $handler->handleUpdate($update);

    Logger::success('webhook', "Successfully handled update #{$updateId}", [
        'update_id' => $updateId,
        'user' => $fromUser
    ]);
} catch (\Throwable $e) {
    Logger::error('webhook', "Error handling update #{$updateId}: " . $e->getMessage(), [
        'exception' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ],
        'update_id' => $updateId
    ]);
}

sendResponse(200, ['ok' => true]);
