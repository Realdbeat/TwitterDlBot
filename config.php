<?php
/**
 * Application Configuration Loader
 */

// Parse .env file if it exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            // Strip surrounding quotes
            if (preg_match('/^([\'"])(.*)\1$/', $val, $m)) {
                $val = $m[2];
            }
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

function env(string $key, mixed $default = null): mixed {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $val;
}

$tempDir = env('TEMP_DIR', __DIR__ . '/tmp');
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0777, true);
}
if (!is_dir($tempDir) || !is_writable($tempDir)) {
    $tempDir = sys_get_temp_dir();
}

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'admin_chat_id' => env('ADMIN_CHAT_ID', ''),
    'temp_dir' => realpath($tempDir) ?: $tempDir,
    'max_file_size_mb' => (int) env('MAX_FILE_SIZE_MB', 50),
    'http_timeout' => (int) env('HTTP_TIMEOUT', 60),
    'webhook_url' => env('WEBHOOK_URL', ''),
    'webhook_secret' => env('WEBHOOK_SECRET', ''),
];
