<?php
/**
 * Monetag Server-to-Server Postback Handler
 *
 * Configured in Monetag Dashboard:
 * https://yourdomain.com/monetag_postback.php?token={ymid}&status={reward_event_type}&zone={zone_id}&price={estimated_price}&telegram_id={telegram_id}
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

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? $_GET['ymid'] ?? $_POST['ymid'] ?? ''));
$status = trim((string)($_GET['status'] ?? $_POST['status'] ?? $_GET['reward_event_type'] ?? ''));
$zoneId = trim((string)($_GET['zone'] ?? $_POST['zone'] ?? $_GET['zone_id'] ?? ''));
$price = trim((string)($_GET['price'] ?? $_POST['price'] ?? $_GET['estimated_price'] ?? ''));
$telegramId = trim((string)($_GET['telegram_id'] ?? $_POST['telegram_id'] ?? ''));

Logger::info('monetag_postback', "Monetag S2S postback received for token: {$token}", [
    'token' => $token,
    'status' => $status,
    'zone_id' => $zoneId,
    'estimated_price' => $price,
    'telegram_id' => $telegramId,
    'query' => $_GET,
    'ip' => Logger::getClientIp()
]);

if (empty($token) || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing token']);
    exit;
}

// In Monetag, valued = user watched the ad and fulfilled reward criteria
if ($status !== '' && $status !== 'valued') {
    Logger::warning('monetag_postback', "Postback received non-valued status: {$status} for token: {$token}");
    http_response_code(200);
    echo json_encode(['ok' => false, 'note' => 'Event not valued']);
    exit;
}

try {
    $db = new Database($config['temp_dir']);
    $bot = new TelegramBot($config['bot_token'], $config['http_timeout']);
    $downloader = new TwitterDownloader($config['http_timeout']);
    $tiktokDownloader = new TikTokDownloader($config['http_timeout']);
    $handler = new BotHandler($bot, $downloader, $config, $tiktokDownloader, $db);

    $result = $handler->deliverPendingAdDownload($token);
    echo json_encode($result);
} catch (\Throwable $e) {
    Logger::error('monetag_postback', "Error processing S2S postback for token {$token}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
