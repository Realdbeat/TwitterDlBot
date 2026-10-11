<?php
/**
 * Telegram Mini App - Monetag Ad Completion & Reward Claim Endpoint
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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['token'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid token parameter']);
    exit;
}

$token = trim((string)$data['token']);
if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token format']);
    exit;
}

$eventData = is_array($data['event'] ?? null) ? $data['event'] : [];

Logger::info('monetag_ad', "Ad claim attempt for token {$token}", [
    'ip' => Logger::getClientIp(),
    'user_agent' => Logger::getUserAgent(),
    'event' => $eventData
]);

try {
    $db = new Database($config['temp_dir']);
    $bot = new TelegramBot($config['bot_token'], $config['http_timeout']);
    $downloader = new TwitterDownloader($config['http_timeout']);
    $tiktokDownloader = new TikTokDownloader($config['http_timeout']);
    $handler = new BotHandler($bot, $downloader, $config, $tiktokDownloader, $db);

    $result = $handler->deliverPendingAdDownload($token);

    if (!($result['ok'] ?? false)) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    Logger::error('monetag_ad', "Fatal exception during ad claim for token {$token}: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error while delivering video']);
}
