<?php
namespace TwitterDlBot;

/**
 * Activity, Security & Error Logger
 * Tracks incoming webhook requests, client IPs, user agents, errors, and system activity.
 */
class Logger {
    private static ?string $logDir = null;
    private static string $logFileName = 'bot_activity.jsonl';
    private static int $maxEntries = 1000;

    /**
     * Initialize logger directory
     */
    public static function init(string $logDir): void {
        self::$logDir = $logDir;
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
    }

    /**
     * Get path to the active log file
     */
    public static function getLogFilePath(): string {
        $dir = self::$logDir ?? sys_get_temp_dir();
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::$logFileName;
    }

    /**
     * Resolves the real client IP address
     */
    public static function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $raw = trim((string)$_SERVER[$header]);
                if (str_contains($raw, ',')) {
                    $parts = explode(',', $raw);
                    $raw = trim($parts[0]);
                }
                if (filter_var($raw, FILTER_VALIDATE_IP)) {
                    return $raw;
                }
            }
        }

        if (PHP_SAPI === 'cli') {
            return '127.0.0.1 (CLI)';
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    }

    /**
     * Resolves client User-Agent
     */
    public static function getUserAgent(): string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua !== '') {
            return trim((string)$ua);
        }
        if (PHP_SAPI === 'cli') {
            return 'CLI Process';
        }
        return '[EMPTY/MISSING]';
    }

    /**
     * Records an event entry
     */
    public static function log(string $level, string $type, string $message, array $context = []): void {
        $filePath = self::getLogFilePath();

        $entry = [
            'id' => bin2hex(random_bytes(6)),
            'timestamp' => date('Y-m-d H:i:s'),
            'timestamp_epoch' => time(),
            'level' => strtoupper($level),
            'type' => strtolower($type),
            'ip' => self::getClientIp(),
            'user_agent' => self::getUserAgent(),
            'method' => PHP_SAPI === 'cli' ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'),
            'uri' => PHP_SAPI === 'cli' ? ($argv[0] ?? 'cli') : ($_SERVER['REQUEST_URI'] ?? '/'),
            'message' => $message,
            'context' => $context,
        ];

        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($filePath, $encoded, FILE_APPEND | LOCK_EX);

        // Also append critical errors to traditional error.log for backwards compatibility
        if ($entry['level'] === 'ERROR' && self::$logDir) {
            $plainErrorFile = rtrim(self::$logDir, '/\\') . DIRECTORY_SEPARATOR . 'error.log';
            $errLine = "[{$entry['timestamp']}] [{$entry['ip']}] {$message}\n";
            if (!empty($context['exception'])) {
                $errLine .= "  Exception: " . json_encode($context['exception']) . "\n";
            }
            @file_put_contents($plainErrorFile, $errLine, FILE_APPEND | LOCK_EX);
        }

        // Periodically rotate log file if it exceeds size
        if (mt_rand(1, 100) === 1) {
            self::pruneLogs();
        }
    }

    public static function info(string $type, string $message, array $context = []): void {
        self::log('INFO', $type, $message, $context);
    }

    public static function warning(string $type, string $message, array $context = []): void {
        self::log('WARNING', $type, $message, $context);
    }

    public static function error(string $type, string $message, array $context = []): void {
        self::log('ERROR', $type, $message, $context);
    }

    public static function success(string $type, string $message, array $context = []): void {
        self::log('SUCCESS', $type, $message, $context);
    }

    /**
     * Reads log entries with optional filtering
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getEntries(int $limit = 300, ?string $level = null, ?string $query = null): array {
        $filePath = self::getLogFilePath();
        if (!file_exists($filePath)) {
            return [];
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        // Newest first
        $lines = array_reverse($lines);
        $entries = [];
        $levelFilter = $level ? strtoupper(trim($level)) : null;
        $queryFilter = $query ? mb_strtolower(trim($query)) : null;

        foreach ($lines as $line) {
            $item = json_decode($line, true);
            if (!is_array($item)) {
                continue;
            }

            if ($levelFilter && ($item['level'] ?? '') !== $levelFilter) {
                continue;
            }

            if ($queryFilter) {
                $haystack = mb_strtolower(
                    ($item['ip'] ?? '') . ' ' .
                    ($item['message'] ?? '') . ' ' .
                    ($item['user_agent'] ?? '') . ' ' .
                    ($item['type'] ?? '') . ' ' .
                    json_encode($item['context'] ?? [])
                );
                if (!str_contains($haystack, $queryFilter)) {
                    continue;
                }
            }

            $entries[] = $item;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Aggregates stats from existing logs
     */
    public static function getStats(): array {
        $filePath = self::getLogFilePath();
        if (!file_exists($filePath)) {
            return [
                'total' => 0,
                'errors' => 0,
                'warnings' => 0,
                'success' => 0,
                'info' => 0,
                'unique_ips' => 0,
                'latest' => null,
                'file_size_kb' => 0
            ];
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $stats = [
            'total' => count($lines),
            'errors' => 0,
            'warnings' => 0,
            'success' => 0,
            'info' => 0,
            'unique_ips' => 0,
            'latest' => null,
            'file_size_kb' => round(filesize($filePath) / 1024, 2)
        ];

        $ips = [];
        foreach ($lines as $line) {
            $item = json_decode($line, true);
            if (!is_array($item)) {
                continue;
            }

            $lvl = $item['level'] ?? '';
            if ($lvl === 'ERROR') $stats['errors']++;
            elseif ($lvl === 'WARNING') $stats['warnings']++;
            elseif ($lvl === 'SUCCESS') $stats['success']++;
            else $stats['info']++;

            if (!empty($item['ip'])) {
                $ips[$item['ip']] = true;
            }

            if (!empty($item['timestamp'])) {
                $stats['latest'] = $item['timestamp'];
            }
        }

        $stats['unique_ips'] = count($ips);
        return $stats;
    }

    /**
     * Deletes log file contents
     */
    public static function clear(): bool {
        $filePath = self::getLogFilePath();
        if (file_exists($filePath)) {
            @file_put_contents($filePath, '');
        }
        if (self::$logDir) {
            $plainErrorFile = rtrim(self::$logDir, '/\\') . DIRECTORY_SEPARATOR . 'error.log';
            if (file_exists($plainErrorFile)) {
                @file_put_contents($plainErrorFile, '');
            }
        }
        return true;
    }

    /**
     * Keep log file within reasonable limits
     */
    private static function pruneLogs(): void {
        $filePath = self::getLogFilePath();
        if (!file_exists($filePath) || filesize($filePath) < 3 * 1024 * 1024) {
            return;
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || count($lines) <= self::$maxEntries) {
            return;
        }

        $retained = array_slice($lines, -self::$maxEntries);
        @file_put_contents($filePath, implode("\n", $retained) . "\n", LOCK_EX);
    }

    /**
     * Records a detailed incoming HTTP request to a plain text file on the server.
     * Captures request IPs (client, remote, forwarded), request address / URL, method, headers, and body.
     */
    public static function logRawRequest(string $filePath, ?string $rawBody = null): bool {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_writable($dir) && self::$logDir && is_writable(self::$logDir)) {
            $filePath = rtrim(self::$logDir, '/\\') . DIRECTORY_SEPARATOR . basename($filePath);
        }

        // Auto-rotate if log file exceeds 10MB to prevent disk exhaustion
        if (file_exists($filePath) && @filesize($filePath) > 10 * 1024 * 1024) {
            @rename($filePath, $filePath . '.' . date('Ymd_His') . '.bak');
        }

        $timestamp = date('Y-m-d H:i:s T');
        $clientIp = self::getClientIp();
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $remotePort = $_SERVER['REMOTE_PORT'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'UNKNOWN');
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host !== '') {
            $fullUrl = "{$scheme}://{$host}{$uri}";
        } else {
            $fullUrl = PHP_SAPI === 'cli' ? "CLI Request ({$uri})" : "{$scheme}://localhost{$uri}";
        }
        $serverAddr = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
        $userAgent = self::getUserAgent();

        $headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                $headers = $all;
            }
        }
        if (empty($headers)) {
            foreach ($_SERVER as $k => $v) {
                if (str_starts_with($k, 'HTTP_')) {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                    $headers[$name] = (string)$v;
                } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $k))));
                    $headers[$name] = (string)$v;
                }
            }
        }

        $body = $rawBody ?? @file_get_contents('php://input');

        $out = "================================================================================\n";
        $out .= "REQUEST TIME:    {$timestamp}\n";
        $out .= "METHOD:          {$method} {$protocol}\n";
        $out .= "REQUEST ADDRESS: {$fullUrl}\n";
        $out .= "REQUEST URI:     {$uri}\n";
        $out .= "CLIENT IP:       {$clientIp}\n";
        $out .= "REMOTE ADDRESS:  {$remoteAddr}" . ($remotePort !== '' ? ":{$remotePort}" : '') . "\n";
        $out .= "SERVER HOST/IP:  {$host} ({$serverAddr})\n";
        $out .= "USER-AGENT:      {$userAgent}\n";

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $out .= "X-FORWARDED-FOR: {$_SERVER['HTTP_X_FORWARDED_FOR']}\n";
        }
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $out .= "CF-CONNECTING-IP:{$_SERVER['HTTP_CF_CONNECTING_IP']}\n";
        }
        if (!empty($_GET)) {
            $out .= "QUERY PARAMS:    " . json_encode($_GET, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        if (!empty($headers)) {
            $out .= "HEADERS:\n";
            foreach ($headers as $hName => $hVal) {
                $out .= "  {$hName}: {$hVal}\n";
            }
        }

        $out .= "BODY / PAYLOAD:\n";
        if ($body !== '' && $body !== false && $body !== null) {
            $decoded = json_decode((string)$body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $out .= json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                $out .= (string)$body . "\n";
            }
        } else {
            $out .= "[EMPTY BODY]\n";
        }
        $out .= "================================================================================\n\n";

        return (bool) @file_put_contents($filePath, $out, FILE_APPEND | LOCK_EX);
    }
}

