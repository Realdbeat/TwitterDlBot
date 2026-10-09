<?php
/**
 * Telegram Bot - Live Activity, Error & IP Monitor Dashboard
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

use TwitterDlBot\TelegramBot;
use TwitterDlBot\Logger;

// Security check: Check password if LOG_PASSWORD is set in .env
$configuredPassword = $config['log_password'] ?? '';
$isAuthenticated = true;

if (!empty($configuredPassword)) {
    // Check URL query param ?key=... or ?password=...
    $providedKey = $_GET['key'] ?? $_GET['password'] ?? $_POST['key'] ?? '';
    if ($providedKey === $configuredPassword) {
        $_SESSION['log_auth'] = true;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        if (($_POST['password'] ?? '') === $configuredPassword) {
            $_SESSION['log_auth'] = true;
            header('Location: log.php');
            exit;
        } else {
            $loginError = "Invalid passcode. Please try again.";
        }
    }

    if (isset($_GET['logout'])) {
        unset($_SESSION['log_auth']);
        header('Location: log.php');
        exit;
    }

    $isAuthenticated = !empty($_SESSION['log_auth']);
}

// Handle API requests (AJAX)
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '' && $isAuthenticated) {
    header('Content-Type: application/json');

    if ($action === 'fetch') {
        $level = !empty($_GET['level']) ? $_GET['level'] : null;
        $search = !empty($_GET['q']) ? $_GET['q'] : null;
        $limit = isset($_GET['limit']) ? max(10, min(500, (int)$_GET['limit'])) : 150;

        $entries = Logger::getEntries($limit, $level, $search);
        $stats = Logger::getStats();

        // Optional webhook check
        $webhookInfo = null;
        if (!empty($config['bot_token']) && $config['bot_token'] !== 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
            try {
                $bot = new TelegramBot($config['bot_token'], 5);
                $wh = $bot->getWebhookInfo();
                if ($wh['ok'] ?? false) {
                    $webhookInfo = $wh['result'] ?? null;
                }
            } catch (\Throwable $e) {
                $webhookInfo = ['error' => $e->getMessage()];
            }
        }

        echo json_encode([
            'ok' => true,
            'stats' => $stats,
            'entries' => $entries,
            'webhook' => $webhookInfo,
            'server' => [
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'time' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
                'log_file' => Logger::getLogFilePath(),
                'bot_script' => __DIR__ . '/bot.php',
                'cron_command' => '* * * * * /usr/local/bin/php ' . __DIR__ . '/bot.php >/dev/null 2>&1'
            ]
        ]);
        exit;
    }

    if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        Logger::clear();
        echo json_encode(['ok' => true, 'message' => 'Logs cleared successfully']);
        exit;
    }

    if ($action === 'delete_webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $bot = new TelegramBot($config['bot_token'], 10);
            $res = $bot->deleteWebhook(true);
            Logger::info('system', 'Webhook deleted via Admin Dashboard', ['result' => $res]);
            echo json_encode($res);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'download') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="bot_logs_' . date('Ymd_His') . '.json"');
        $entries = Logger::getEntries(1000);
        echo json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Render Login Page if not authenticated
if (!$isAuthenticated): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Monitor - Authentication</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --card-bg: rgba(17, 24, 39, 0.85);
            --border: rgba(255, 255, 255, 0.1);
            --accent: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.35);
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at 50% 0%, #1e1b4b 0%, var(--bg) 75%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            backdrop-filter: blur(12px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 50px var(--accent-glow);
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
        }
        .logo-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        h1 { font-size: 1.4rem; font-weight: 700; }
        p { color: var(--text-muted); font-size: 0.9rem; margin-top: 4px; }
        .input-group { margin: 1.75rem 0 1.25rem; }
        label { display: block; font-size: 0.82rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 8px; }
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #fff;
            font-size: 1rem;
            outline: none;
            transition: all 0.2s;
        }
        input[type="password"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        button:hover { opacity: 0.92; }
        .alert {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <div class="logo-icon">🛡️</div>
            <div>
                <h1>Bot Log Monitor</h1>
                <p>Authentication Required</p>
            </div>
        </div>

        <?php if (!empty($loginError)): ?>
            <div class="alert"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="input-group">
                <label for="password">Dashboard Passcode</label>
                <input type="password" id="password" name="password" placeholder="Enter LOG_PASSWORD from .env" required autofocus>
            </div>
            <button type="submit">Unlock Dashboard</button>
        </form>
    </div>
</body>
</html>
<?php exit; endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Bot Monitor - Logs, IPs & Errors</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --surface: #111827;
            --surface-elevated: #1f2937;
            --border: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(255, 255, 255, 0.18);
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --text-dim: #6b7280;
            --accent-blue: #3b82f6;
            --accent-indigo: #6366f1;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --accent-purple: #a855f7;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-sans);
            background-color: var(--bg);
            background-image: 
                radial-gradient(at 0% 0%, rgba(30, 27, 75, 0.4) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(15, 23, 42, 0.6) 0px, transparent 50%);
            color: var(--text-main);
            min-height: 100vh;
            padding: 1.5rem 2rem;
            line-height: 1.5;
        }

        /* Header Bar */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.75rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .brand-badge {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.35);
        }
        .brand-info h1 {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .brand-info p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.84rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-main);
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .btn:hover {
            border-color: var(--border-hover);
            background: var(--surface-elevated);
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            border-color: rgba(59, 130, 246, 0.4);
            color: white;
        }
        .btn-primary:hover {
            opacity: 0.92;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.4);
        }
        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.35);
            color: #f87171;
        }
        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.25);
            border-color: rgba(239, 68, 68, 0.6);
        }

        /* Grid Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.2rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--border-hover);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
        }
        .stat-card.blue::before { background: var(--accent-blue); }
        .stat-card.red::before { background: var(--accent-red); }
        .stat-card.amber::before { background: var(--accent-amber); }
        .stat-card.green::before { background: var(--accent-green); }
        .stat-card.purple::before { background: var(--accent-purple); }

        .stat-label {
            font-size: 0.76rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-top: 6px;
            letter-spacing: -0.02em;
            font-family: var(--font-mono);
        }
        .stat-desc {
            font-size: 0.78rem;
            color: var(--text-dim);
            margin-top: 4px;
        }

        /* Webhook Alert / Status Banner */
        .webhook-banner {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .webhook-banner.error {
            border-color: rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.08);
        }
        .webhook-banner.healthy {
            border-color: rgba(16, 185, 129, 0.4);
            background: rgba(16, 185, 129, 0.08);
        }
        .wh-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .wh-title {
            font-size: 0.95rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .wh-desc {
            font-size: 0.84rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        /* Main Log Table Area */
        .main-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }
        .controls-bar {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            background: rgba(255, 255, 255, 0.015);
        }
        .search-box {
            position: relative;
            flex: 1;
            min-width: 240px;
        }
        .search-box input {
            width: 100%;
            padding: 8px 12px 8px 34px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #fff;
            font-size: 0.86rem;
            outline: none;
            transition: all 0.2s;
        }
        .search-box input:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }
        .search-box::before {
            content: '🔍';
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.82rem;
            opacity: 0.6;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        select {
            padding: 8px 12px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 0.84rem;
            outline: none;
            cursor: pointer;
        }

        /* Table Design */
        .table-responsive {
            overflow-x: auto;
            max-height: 640px;
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.84rem;
        }
        thead {
            background: rgba(15, 23, 42, 0.95);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        th {
            padding: 12px 14px;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            transition: background 0.15s;
            cursor: pointer;
        }
        tbody tr:hover {
            background: rgba(255, 255, 255, 0.035);
        }
        td {
            padding: 11px 14px;
            vertical-align: middle;
            color: var(--text-main);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-family: var(--font-mono);
        }
        .badge-error { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); }
        .badge-warning { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
        .badge-info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.35); }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }

        .type-tag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .ip-cell {
            font-family: var(--font-mono);
            font-weight: 500;
            color: #93c5fd;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .ua-cell {
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-dim);
            font-size: 0.78rem;
        }
        .ua-empty {
            color: #f87171;
            font-weight: 600;
            background: rgba(239, 68, 68, 0.12);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .msg-cell {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Detail Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1.5rem;
        }
        .modal-overlay.open { display: flex; }
        .modal-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            width: 100%;
            max-width: 760px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6);
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .json-block {
            background: #090d16;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 14px;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: #d1d5db;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 350px;
            overflow-y: auto;
        }

        /* Live Indicator */
        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent-green);
            box-shadow: 0 0 10px var(--accent-green);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-dim);
        }
        .empty-icon { font-size: 2.5rem; margin-bottom: 0.5rem; }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .header-actions { width: 100%; justify-content: flex-start; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header>
        <div class="brand">
            <div class="brand-badge">📡</div>
            <div class="brand-info">
                <h1>
                    Telegram Bot Activity & IP Monitor
                    <span class="live-dot" title="Live Auto-Monitor"></span>
                </h1>
                <p id="server-meta">Loading server environment...</p>
            </div>
        </div>

        <div class="header-actions">
            <select id="auto-refresh-select" onchange="updateAutoRefresh()">
                <option value="5">Auto-refresh: 5s</option>
                <option value="10" selected>Auto-refresh: 10s</option>
                <option value="30">Auto-refresh: 30s</option>
                <option value="0">Auto-refresh: Off</option>
            </select>
            <button class="btn btn-primary" onclick="fetchLogs(true)">🔄 Refresh</button>
            <a href="log.php?action=download" class="btn">📥 Export JSON</a>
            <button class="btn btn-danger" onclick="confirmClearLogs()">🗑️ Clear Logs</button>
            <?php if (!empty($configuredPassword)): ?>
                <a href="log.php?logout=1" class="btn">🔒 Logout</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Webhook Status Card -->
    <div id="webhook-banner" class="webhook-banner">
        <div class="wh-details">
            <div class="wh-title" id="wh-title">
                <span>🔄 Checking Telegram Webhook Status...</span>
            </div>
            <div class="wh-desc" id="wh-desc">Connecting to Telegram Bot API...</div>
        </div>
        <div class="wh-action" id="wh-action"></div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-label">Total Requests</div>
            <div class="stat-value" id="stat-total">0</div>
            <div class="stat-desc">Captured events & hits</div>
        </div>
        <div class="stat-card red">
            <div class="stat-label">Errors Encountered</div>
            <div class="stat-value" id="stat-errors">0</div>
            <div class="stat-desc">Exceptions & 403 blocks</div>
        </div>
        <div class="stat-card amber">
            <div class="stat-label">Warnings</div>
            <div class="stat-value" id="stat-warnings">0</div>
            <div class="stat-desc">Bad requests / missing data</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-label">Unique Client IPs</div>
            <div class="stat-value" id="stat-ips">0</div>
            <div class="stat-desc">Unique visitors & Telegram IPs</div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Last Activity</div>
            <div class="stat-value" style="font-size: 1.15rem; margin-top: 14px;" id="stat-latest">None</div>
            <div class="stat-desc" id="stat-filesize">Log size: 0 KB</div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="main-card">
        <div class="controls-bar">
            <div class="search-box">
                <input type="text" id="search-input" placeholder="Search by IP, username, message or User-Agent..." oninput="debounceFetch()">
            </div>
            <div class="filter-group">
                <select id="level-filter" onchange="fetchLogs()">
                    <option value="">All Levels</option>
                    <option value="ERROR">Errors Only (🔴)</option>
                    <option value="WARNING">Warnings (🟡)</option>
                    <option value="SUCCESS">Success (🟢)</option>
                    <option value="INFO">Info (🔵)</option>
                </select>
                <select id="limit-filter" onchange="fetchLogs()">
                    <option value="50">50 entries</option>
                    <option value="150" selected>150 entries</option>
                    <option value="300">300 entries</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Level</th>
                        <th>Type</th>
                        <th>Client IP</th>
                        <th>User-Agent</th>
                        <th>Method</th>
                        <th>Summary Message</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="logs-tbody">
                    <tr>
                        <td colspan="8" class="empty-state">
                            <div class="empty-icon">⏳</div>
                            <div>Loading log activity...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Detail Inspection Modal -->
    <div id="detail-modal" class="modal-overlay" onclick="closeModalOnOverlay(event)">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-title">Event Inspection</h3>
                <button class="btn" onclick="closeModal()">✕ Close</button>
            </div>
            <div class="modal-body" id="modal-content">
                <!-- Injected via JavaScript -->
            </div>
        </div>
    </div>

    <script>
        let currentEntries = [];
        let refreshInterval = null;
        let debounceTimer = null;

        function updateAutoRefresh() {
            if (refreshInterval) clearInterval(refreshInterval);
            const seconds = parseInt(document.getElementById('auto-refresh-select').value, 10);
            if (seconds > 0) {
                refreshInterval = setInterval(() => fetchLogs(false), seconds * 1000);
            }
        }

        function debounceFetch() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => fetchLogs(), 300);
        }

        async function fetchLogs(showLoading = false) {
            const level = document.getElementById('level-filter').value;
            const limit = document.getElementById('limit-filter').value;
            const q = document.getElementById('search-input').value;

            try {
                const params = new URLSearchParams({
                    action: 'fetch',
                    level: level,
                    limit: limit,
                    q: q
                });

                const res = await fetch(`log.php?${params.toString()}`);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();

                renderDashboard(data);
            } catch (err) {
                console.error('Failed to fetch logs:', err);
            }
        }

        function renderDashboard(data) {
            window.lastServerData = data.server || {};
            // Update Stats
            const s = data.stats || {};
            document.getElementById('stat-total').innerText = s.total || 0;
            document.getElementById('stat-errors').innerText = s.errors || 0;
            document.getElementById('stat-warnings').innerText = s.warnings || 0;
            document.getElementById('stat-ips').innerText = s.unique_ips || 0;
            document.getElementById('stat-latest').innerText = s.latest ? s.latest.split(' ')[1] : 'None';
            document.getElementById('stat-filesize').innerText = `Log file: ${s.file_size_kb || 0} KB`;

            // Server Meta
            if (data.server) {
                document.getElementById('server-meta').innerText = 
                    `PHP ${data.server.php_version} • Server: ${data.server.server_software} • ${data.server.time}`;
            }

            // Webhook Banner
            renderWebhookStatus(data.webhook);

            // Render Table
            currentEntries = data.entries || [];
            const tbody = document.getElementById('logs-tbody');

            if (currentEntries.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="empty-state">
                            <div class="empty-icon">📂</div>
                            <div style="font-weight: 600;">No log entries found</div>
                            <div style="font-size: 0.8rem; margin-top: 4px;">When users or Telegram message the bot, requests and errors will appear here in real time.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            currentEntries.forEach((entry, idx) => {
                const badgeClass = {
                    'ERROR': 'badge-error',
                    'WARNING': 'badge-warning',
                    'SUCCESS': 'badge-success',
                    'INFO': 'badge-info'
                }[entry.level] || 'badge-info';

                const isMissingUa = !entry.user_agent || entry.user_agent === '[EMPTY/MISSING]';
                const uaDisplay = isMissingUa 
                    ? `<span class="ua-empty" title="Missing User-Agent triggers 403 Forbidden on ModSecurity">[EMPTY/MISSING]</span>`
                    : escapeHtml(entry.user_agent);

                html += `
                    <tr onclick="openModal(${idx})">
                        <td style="font-family: var(--font-mono); font-size: 0.78rem; white-space: nowrap; color: var(--text-muted);">
                            ${escapeHtml(entry.timestamp)}
                        </td>
                        <td><span class="badge ${badgeClass}">${escapeHtml(entry.level)}</span></td>
                        <td><span class="type-tag">${escapeHtml(entry.type)}</span></td>
                        <td><span class="ip-cell">${escapeHtml(entry.ip)}</span></td>
                        <td><div class="ua-cell">${uaDisplay}</div></td>
                        <td style="font-family: var(--font-mono); font-size: 0.76rem; font-weight: 600;">${escapeHtml(entry.method)}</td>
                        <td><div class="msg-cell" title="${escapeHtml(entry.message)}">${escapeHtml(entry.message)}</div></td>
                        <td><button class="btn" style="padding: 3px 8px; font-size: 0.74rem;">Inspect</button></td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function renderWebhookStatus(wh) {
            const banner = document.getElementById('webhook-banner');
            const title = document.getElementById('wh-title');
            const desc = document.getElementById('wh-desc');
            const action = document.getElementById('wh-action');

            if (!wh) {
                banner.className = 'webhook-banner';
                title.innerHTML = '⚠️ <span>Telegram Bot Token Not Verified</span>';
                desc.innerText = 'Check your TELEGRAM_BOT_TOKEN in .env';
                action.innerHTML = '';
                return;
            }

            if (wh.error) {
                banner.className = 'webhook-banner error';
                title.innerHTML = '❌ <span>Telegram Connection Error</span>';
                desc.innerText = wh.error;
                action.innerHTML = '';
                return;
            }

            const url = wh.url || 'Not Registered';
            const pending = wh.pending_update_count || 0;
            const lastError = wh.last_error_message || '';
            const lastErrorDate = wh.last_error_date ? new Date(wh.last_error_date * 1000).toLocaleString() : '';

            if (lastError.includes('403 Forbidden')) {
                banner.className = 'webhook-banner error';
                title.innerHTML = `🚨 <span>Webhook Blocked: 403 Forbidden (ModSecurity / Web Application Firewall)</span>`;
                desc.innerHTML = `URL: <b>${escapeHtml(url)}</b> | Pending updates in queue: <b style="color: #f87171;">${pending}</b><br>` +
                    `Error: <span style="color: #f87171;">${escapeHtml(lastError)}</span> (${lastErrorDate})<br>` +
                    `💡 <i>Fix: Go to cPanel > ModSecurity and toggle OFF for this domain, or switch to Long Polling (php bot.php).</i>`;
                action.innerHTML = `<button class="btn btn-danger" onclick="deleteWebhookAction()">Clear Webhook (Enable Polling)</button>`;
            } else if (lastError) {
                banner.className = 'webhook-banner error';
                title.innerHTML = `⚠️ <span>Webhook Error Reported by Telegram</span>`;
                desc.innerHTML = `URL: <b>${escapeHtml(url)}</b> | Pending updates: <b>${pending}</b><br>` +
                    `Error: ${escapeHtml(lastError)} (${lastErrorDate})`;
                action.innerHTML = `<button class="btn btn-danger" onclick="deleteWebhookAction()">Clear Webhook</button>`;
            } else if (!wh.url) {
                const cronCmd = (window.lastServerData && window.lastServerData.cron_command) 
                    ? window.lastServerData.cron_command 
                    : '* * * * * php /home/username/public_html/bot.php >/dev/null 2>&1';
                banner.className = 'webhook-banner';
                title.innerHTML = `ℹ️ <span>Long Polling Mode Active (Webhook Cleared)</span>`;
                desc.innerHTML = `No webhook is active. Telegram updates can now be received by <b>bot.php</b>.<br>` +
                    `⏰ <b>cPanel Cron Setup:</b> In cPanel &gt; Cron Jobs, add a job running every minute (<code>* * * * *</code>):<br>` +
                    `<code style="background: rgba(0,0,0,0.5); padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 4px; color: #a5f3fc;">${escapeHtml(cronCmd)}</code>`;
                action.innerHTML = '';
            } else {
                banner.className = 'webhook-banner healthy';
                title.innerHTML = `✅ <span>Telegram Webhook Healthy & Receiving Updates</span>`;
                desc.innerHTML = `URL: <b>${escapeHtml(url)}</b> | Pending updates in queue: <b>${pending}</b>`;
                action.innerHTML = `<button class="btn" onclick="deleteWebhookAction()">Delete Webhook</button>`;
            }
        }

        async function deleteWebhookAction() {
            if (!confirm('Are you sure you want to remove the webhook? This will allow php bot.php to fetch updates directly.')) return;
            try {
                const res = await fetch('log.php?action=delete_webhook', { method: 'POST' });
                const data = await res.json();
                alert(data.ok ? 'Webhook cleared successfully!' : 'Error: ' + JSON.stringify(data));
                fetchLogs();
            } catch (err) {
                alert('Request failed: ' + err.message);
            }
        }

        function openModal(index) {
            const entry = currentEntries[index];
            if (!entry) return;

            document.getElementById('modal-title').innerText = `Event #${entry.id} [${entry.level}]`;
            
            let html = `
                <div>
                    <strong style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Overview</strong>
                    <div style="margin-top: 6px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.86rem;">
                        <div><b>Timestamp:</b> ${escapeHtml(entry.timestamp)}</div>
                        <div><b>Client IP:</b> <code style="color: #93c5fd;">${escapeHtml(entry.ip)}</code></div>
                        <div><b>HTTP Method:</b> ${escapeHtml(entry.method)}</div>
                        <div><b>Endpoint URI:</b> ${escapeHtml(entry.uri || '/')}</div>
                    </div>
                </div>

                <div>
                    <strong style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">User-Agent Header</strong>
                    <div style="margin-top: 6px; font-family: var(--font-mono); font-size: 0.82rem; background: #090d16; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        ${escapeHtml(entry.user_agent)}
                    </div>
                </div>

                <div>
                    <strong style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Summary Message</strong>
                    <div style="margin-top: 6px; font-size: 0.9rem;">${escapeHtml(entry.message)}</div>
                </div>

                <div>
                    <strong style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Full Context / Payload JSON</strong>
                    <pre class="json-block">${escapeHtml(JSON.stringify(entry.context || {}, null, 2))}</pre>
                </div>
            `;

            document.getElementById('modal-content').innerHTML = html;
            document.getElementById('detail-modal').classList.add('open');
        }

        function closeModal() {
            document.getElementById('detail-modal').classList.remove('open');
        }

        function closeModalOnOverlay(e) {
            if (e.target.id === 'detail-modal') closeModal();
        }

        async function confirmClearLogs() {
            if (!confirm('Are you sure you want to clear all recorded logs and errors?')) return;
            try {
                const res = await fetch('log.php?action=clear', { method: 'POST' });
                const data = await res.json();
                if (data.ok) fetchLogs();
            } catch (err) {
                alert('Failed to clear logs: ' + err.message);
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Initialize on load
        fetchLogs();
        updateAutoRefresh();
    </script>
</body>
</html>
