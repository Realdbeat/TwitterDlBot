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
        if (PHP_SAPI === 'cli') {
            return '127.0.0.1 (CLI)';
        }

        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $raw = trim($_SERVER[$header]);
                if (str_contains($raw, ',')) {
                    $parts = explode(',', $raw);
                    $raw = trim($parts[0]);
                }
                if (filter_var($raw, FILTER_VALIDATE_IP)) {
                    return $raw;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    }

    /**
     * Resolves client User-Agent
     */
    public static function getUserAgent(): string {
        if (PHP_SAPI === 'cli') {
            return 'CLI Process';
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return $ua !== '' ? trim($ua) : '[EMPTY/MISSING]';
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
}
