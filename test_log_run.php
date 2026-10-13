<?php
require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
$_SERVER['REMOTE_PORT'] = '45123';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.88';
$_SERVER['HTTP_HOST'] = 'xdlbot.mossubyte.com.ng';
$_SERVER['REQUEST_URI'] = '/webhook.php?secret=test123';
$_SERVER['HTTP_USER_AGENT'] = 'TelegramBot (like TwitterBot)';
$_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

$testFile = __DIR__ . '/test_webhook_log.txt';
\TwitterDlBot\Logger::logRawRequest($testFile, json_encode(['update_id' => 9999, 'message' => ['from' => ['username' => 'testuser'], 'text' => 'https://x.com/user/status/123']]));

echo file_get_contents($testFile);
unlink($testFile);
