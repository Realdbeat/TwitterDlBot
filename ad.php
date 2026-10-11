<?php
/**
 * Telegram Mini App - Monetag Rewarded Interstitial Ad Viewer
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
$config = require __DIR__ . '/config.php';

use TwitterDlBot\Database;

$token = trim((string)($_GET['token'] ?? ''));
$isValidToken = preg_match('/^[a-f0-9]{32}$/i', $token);

$pendingData = null;
$isAlreadyCompleted = false;

if ($isValidToken) {
    try {
        $db = new Database($config['temp_dir']);
        $pendingData = $db->getPendingAdDownload($token);
        if ($pendingData && $pendingData['status'] === 'completed') {
            $isAlreadyCompleted = true;
        }
    } catch (\Throwable $e) {
        // Continue with minimal payload
    }
}

$zoneId = !empty($config['monetag_zone_id']) ? $config['monetag_zone_id'] : '12002089';
$sdkUrl = !empty($config['monetag_sdk_url']) ? $config['monetag_sdk_url'] : '//libtl.com/sdk.js';

$linkType = $pendingData['link_type'] ?? 'media';
$linkData = $pendingData['link_data_array'] ?? [];
$authorName = $linkData['user'] ?? '';
$platformName = ($linkType === 'tiktok') ? 'TikTok' : 'Twitter / X';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Watch Ad to Unlock Video - TwitterDlBot</title>
    <!-- Telegram Mini App Official SDK -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    
    <!-- Monetag Rewarded Interstitial SDK Tag -->
    <script src='<?= htmlspecialchars($sdkUrl) ?>' data-zone='<?= htmlspecialchars($zoneId) ?>' data-sdk='show_<?= htmlspecialchars($zoneId) ?>'></script>

    <!-- Google Fonts for Premium Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(22, 29, 49, 0.75);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-color: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-gradient: linear-gradient(135deg, #38bdf8 0%, #6366f1 50%, #ec4899 100%);
            --btn-gradient: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 50%, #8b5cf6 100%);
            --btn-glow: rgba(59, 130, 246, 0.45);
            --success-color: #10b981;
            --success-glow: rgba(16, 185, 129, 0.4);
            --error-color: #ef4444;
            --font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        /* Use Telegram theme variables if available */
        body.tg-theme {
            --bg-color: var(--tg-theme-bg-color, #0b0f19);
            --text-color: var(--tg-theme-text-color, #f8fafc);
            --text-secondary: var(--tg-theme-hint-color, #94a3b8);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: var(--font-body);
            background: var(--bg-color);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(56, 189, 248, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.15) 0%, transparent 45%),
                radial-gradient(circle at 50% 50%, rgba(236, 72, 153, 0.06) 0%, transparent 60%);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
        }

        .container {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 28px;
            padding: 32px 24px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6), 0 0 50px -20px rgba(56, 189, 248, 0.15);
            text-align: center;
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -50%;
            width: 200%;
            height: 2px;
            background: var(--accent-gradient);
            animation: shimmer 4s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: translateX(-30%); }
            100% { transform: translateX(30%); }
        }

        .platform-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            color: #38bdf8;
            margin-bottom: 20px;
            letter-spacing: 0.3px;
        }

        .platform-badge svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
        }

        .media-icon-wrapper {
            position: relative;
            width: 86px;
            height: 86px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .media-icon-glow {
            position: absolute;
            inset: -4px;
            background: var(--btn-gradient);
            border-radius: 26px;
            opacity: 0.65;
            filter: blur(12px);
            animation: pulseGlow 3s infinite alternate;
        }

        @keyframes pulseGlow {
            0% { transform: scale(0.95); opacity: 0.5; }
            100% { transform: scale(1.05); opacity: 0.85; }
        }

        .media-icon-box {
            position: relative;
            width: 86px;
            height: 86px;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
        }

        h1 {
            font-family: var(--font-family);
            font-size: 24px;
            font-weight: 800;
            line-height: 1.25;
            margin-bottom: 10px;
            color: #ffffff;
            letter-spacing: -0.3px;
        }

        p.description {
            font-size: 14.5px;
            color: var(--text-secondary);
            line-height: 1.55;
            margin-bottom: 24px;
            padding: 0 10px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(14, 165, 233, 0.25);
            color: #38bdf8;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

        .status-pill .indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #38bdf8;
            box-shadow: 0 0 10px #38bdf8;
            animation: blink 1.5s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .btn-action {
            width: 100%;
            padding: 16px 24px;
            background: var(--btn-gradient);
            color: #ffffff;
            border: none;
            border-radius: 18px;
            font-family: var(--font-family);
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.2px;
            cursor: pointer;
            box-shadow: 0 12px 28px -6px var(--btn-glow);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .btn-action:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 16px 32px -4px var(--btn-glow);
        }

        .btn-action:active:not(:disabled) {
            transform: translateY(1px) scale(0.98);
        }

        .btn-action:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2.5px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .footer-note {
            margin-top: 20px;
            font-size: 12px;
            color: rgba(148, 163, 184, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* Success & Error Overlay / States */
        .state-container {
            display: none;
            text-align: center;
            animation: fadeIn 0.35s ease-out;
        }

        .success-icon-box {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: rgba(16, 185, 129, 0.15);
            border: 2px solid var(--success-color);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 35px var(--success-glow);
            animation: scaleIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes scaleIn {
            0% { transform: scale(0.5); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon-box svg {
            width: 44px;
            height: 44px;
            fill: none;
            stroke: var(--success-color);
            stroke-width: 3.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px dashed rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 14px;
            margin-top: 20px;
            font-size: 12.5px;
            color: #94a3b8;
            text-align: left;
        }

        .demo-mode-tag {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 12px;
        }
    </style>
</head>
<body class="tg-theme">

<div class="container">
    <div class="card" id="mainCard">
        <?php if (!$isValidToken): ?>
            <!-- Invalid Token State -->
            <div class="media-icon-wrapper">
                <div class="media-icon-box" style="color: #ef4444;">⚠️</div>
            </div>
            <h1>Invalid Request</h1>
            <p class="description">This video link is invalid or has expired. Please send a new link to the bot.</p>
            <button class="btn-action" id="btnCloseInvalid" onclick="closeMiniApp()">Close App</button>

        <?php elseif ($isAlreadyCompleted): ?>
            <!-- Already Completed State -->
            <div class="success-icon-box">
                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h1>Already Delivered!</h1>
            <p class="description">Your video has already been unlocked and delivered to your Telegram chat.</p>
            <button class="btn-action" id="btnCloseDelivered" onclick="closeMiniApp()">Return to Chat</button>

        <?php else: ?>
            <!-- Active Ad View State -->
            <div id="adViewState">
                <div class="platform-badge">
                    <span>🎬</span>
                    <span><?= htmlspecialchars($platformName) ?> Video Ready</span>
                </div>

                <div class="media-icon-wrapper">
                    <div class="media-icon-glow"></div>
                    <div class="media-icon-box">🎁</div>
                </div>

                <h1>Unlock Your Video</h1>
                <p class="description">
                    <?php if (!empty($authorName)): ?>
                        Video from <b>@<?= htmlspecialchars($authorName) ?></b> is ready!<br>
                    <?php endif; ?>
                    Watch a quick sponsored ad to support our bot and receive your high-definition video directly in Telegram.
                </p>

                <?php if (empty($zoneId)): ?>
                    <div class="demo-mode-tag">Demo Mode (Zone ID Not Configured in .env)</div>
                <?php endif; ?>

                <div class="status-pill" id="statusPill">
                    <span class="indicator" id="statusIndicator"></span>
                    <span id="statusText">Preparing sponsored ad...</span>
                </div>

                <button class="btn-action" id="btnWatchAd" onclick="handleWatchAdClick()" disabled>
                    <span id="btnIcon">🎬</span>
                    <span id="btnText">Loading Ad...</span>
                </button>

                <div class="footer-note">
                    🔒 <span>Secure • Watermark-free download unlocked upon completion</span>
                </div>
            </div>

            <!-- Claiming / Delivering State -->
            <div class="state-container" id="claimingState">
                <div class="media-icon-wrapper">
                    <div class="media-icon-glow"></div>
                    <div class="media-icon-box">
                        <div class="spinner" style="width: 38px; height: 38px; border-width: 3.5px;"></div>
                    </div>
                </div>
                <h1>Delivering Video...</h1>
                <p class="description">Ad verified! Processing video download and sending it to your Telegram chat now...</p>
            </div>

            <!-- Success State -->
            <div class="state-container" id="successState">
                <div class="success-icon-box">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
                <h1>Video Unlocked! 🎉</h1>
                <p class="description">Your video has been delivered to your chat. Returning you back to Telegram...</p>
                <button class="btn-action" onclick="closeMiniApp()">Open Video</button>
            </div>

            <!-- Error / Retry State -->
            <div class="state-container" id="errorState">
                <div class="media-icon-wrapper">
                    <div class="media-icon-box" style="color: #ef4444;">❌</div>
                </div>
                <h1 id="errorTitle">Ad Did Not Complete</h1>
                <p class="description" id="errorDesc">Please watch the full ad to unlock your video.</p>
                <button class="btn-action" id="btnRetryAd" onclick="resetToWatchAd()">Try Again</button>
                <div style="margin-top: 12px;">
                    <button style="background: transparent; border: none; color: #94a3b8; font-size: 13px; cursor: pointer; text-decoration: underline;" onclick="claimRewardFallback()">Unlock Anyway (Fallback)</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const token = <?= json_encode($token) ?>;
    const zoneId = <?= json_encode($zoneId) ?>;
    const sdkFunction = zoneId ? ('show_' + zoneId) : null;

    let tgWebApp = null;
    let isPreloaded = false;
    let isAdInProgress = false;

    // Initialize Telegram WebApp SDK
    function initTelegram() {
        if (window.Telegram && window.Telegram.WebApp) {
            tgWebApp = window.Telegram.WebApp;
            try {
                tgWebApp.ready();
                tgWebApp.expand();
                if (tgWebApp.themeParams && tgWebApp.themeParams.bg_color) {
                    document.body.classList.add('tg-theme');
                }
            } catch (e) {
                console.warn('Telegram WebApp initialization error:', e);
            }
        }
    }

    function closeMiniApp() {
        if (tgWebApp && typeof tgWebApp.close === 'function') {
            tgWebApp.close();
        } else {
            window.close();
        }
    }

    function triggerHaptic(type) {
        try {
            if (tgWebApp && tgWebApp.HapticFeedback) {
                if (type === 'success') {
                    tgWebApp.HapticFeedback.notificationOccurred('success');
                } else if (type === 'error') {
                    tgWebApp.HapticFeedback.notificationOccurred('error');
                } else {
                    tgWebApp.HapticFeedback.impactOccurred('medium');
                }
            }
        } catch (e) {}
    }

    // Set Status Message on UI
    function setStatus(text, isReady = false) {
        const statusText = document.getElementById('statusText');
        const statusIndicator = document.getElementById('statusIndicator');
        if (statusText) statusText.innerText = text;
        if (statusIndicator) {
            statusIndicator.style.background = isReady ? '#10b981' : '#38bdf8';
            statusIndicator.style.boxShadow = isReady ? '0 0 10px #10b981' : '0 0 10px #38bdf8';
        }
    }

    function enableWatchButton() {
        const btn = document.getElementById('btnWatchAd');
        const btnText = document.getElementById('btnText');
        if (btn && btnText) {
            btn.disabled = false;
            btnText.innerText = 'Watch Ad to Unlock Video';
            setStatus('Ad is ready to watch!', true);
        }
    }

    // Preload Monetag Rewarded Interstitial
    function initMonetagAd() {
        if (!zoneId || !sdkFunction) {
            // Zone ID not configured - allow demo test
            setTimeout(() => {
                enableWatchButton();
                setStatus('Ready (Demo Mode)', true);
            }, 600);
            return;
        }

        // Wait for Monetag SDK to attach to window
        let checkCount = 0;
        const maxChecks = 40; // 4 seconds total
        const interval = setInterval(() => {
            checkCount++;
            if (typeof window[sdkFunction] === 'function') {
                clearInterval(interval);
                setStatus('Preloading sponsor ad...');
                try {
                    window[sdkFunction]({ type: 'preload', ymid: token })
                        .then(() => {
                            isPreloaded = true;
                            enableWatchButton();
                        })
                        .catch((err) => {
                            console.warn('Monetag preload notice:', err);
                            // Even if preload returns empty or catches, enable button so user can attempt show
                            enableWatchButton();
                        });
                } catch (e) {
                    console.error('Preload call error:', e);
                    enableWatchButton();
                }
            } else if (checkCount >= maxChecks) {
                clearInterval(interval);
                console.warn('Monetag SDK not detected within timeout.');
                enableWatchButton();
                setStatus('Ready to play', true);
            }
        }, 100);
    }

    // Handle Button Click to Show Rewarded Interstitial
    function handleWatchAdClick() {
        if (isAdInProgress) return;
        isAdInProgress = true;
        triggerHaptic('impact');

        const btn = document.getElementById('btnWatchAd');
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');

        if (btn) btn.disabled = true;
        if (btnText) btnText.innerText = 'Opening Ad...';
        if (btnIcon) btnIcon.innerHTML = '<div class="spinner"></div>';

        // Check if Demo Mode (no zone configured)
        if (!zoneId || !sdkFunction || typeof window[sdkFunction] !== 'function') {
            setTimeout(() => {
                claimReward({ demo: true });
            }, 1200);
            return;
        }

        // Call Monetag SDK function: show_12002089() or show_XXX({ ymid: token })
        try {
            const showFn = (typeof window[sdkFunction] === 'function') 
                ? window[sdkFunction] 
                : (typeof window.show_12002089 === 'function' ? window.show_12002089 : null);

            if (!showFn) {
                console.warn('Monetag show function not found, claiming reward in demo fallback');
                setTimeout(() => { claimReward({ demo: true }); }, 1000);
                return;
            }

            // Execute show_12002089() with tracking token
            const promise = showFn({ ymid: token }) || showFn();
            if (promise && typeof promise.then === 'function') {
                promise.then((result) => {
                    console.log('Monetag ad completed successfully:', result);
                    // User reward function: unlocks and delivers video to Telegram!
                    claimReward(result || {});
                }).catch((err) => {
                    console.error('Monetag ad error or closed early:', err);
                    isAdInProgress = false;
                    showErrorState('Ad Incomplete', 'The ad was closed before finishing. Please watch the ad to unlock your video.');
                });
            } else {
                claimReward({});
            }
        } catch (e) {
            console.error('Fatal calling Monetag SDK:', e);
            isAdInProgress = false;
            showErrorState('Ad Error', 'Failed to initialize ad player. Please try again.');
        }
    }

    // Claim reward by notifying backend server
    function claimReward(eventDetails = {}) {
        document.getElementById('adViewState').style.display = 'none';
        document.getElementById('claimingState').style.display = 'block';

        fetch('ad_claim.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                token: token,
                event: eventDetails
            })
        })
        .then(res => res.json())
        .then(data => {
            document.getElementById('claimingState').style.display = 'none';

            if (data.ok) {
                triggerHaptic('success');
                document.getElementById('successState').style.display = 'block';
                // Auto close TMA after 2.5s to take user directly to their video
                setTimeout(() => {
                    closeMiniApp();
                }, 2500);
            } else {
                triggerHaptic('error');
                showErrorState('Download Failed', data.error || 'Could not send video to Telegram. Please try again.');
            }
        })
        .catch(err => {
            console.error('Claim network error:', err);
            document.getElementById('claimingState').style.display = 'none';
            triggerHaptic('error');
            showErrorState('Connection Error', 'Network timeout while contacting bot server. Please try again.');
        });
    }

    function claimRewardFallback() {
        claimReward({ fallback: true });
    }

    function showErrorState(title, desc) {
        document.getElementById('adViewState').style.display = 'none';
        document.getElementById('claimingState').style.display = 'none';
        document.getElementById('successState').style.display = 'none';

        const errorState = document.getElementById('errorState');
        const errorTitle = document.getElementById('errorTitle');
        const errorDesc = document.getElementById('errorDesc');

        if (errorTitle) errorTitle.innerText = title;
        if (errorDesc) errorDesc.innerText = desc;
        if (errorState) errorState.style.display = 'block';
    }

    function resetToWatchAd() {
        isAdInProgress = false;
        document.getElementById('errorState').style.display = 'none';
        document.getElementById('adViewState').style.display = 'block';

        const btn = document.getElementById('btnWatchAd');
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');

        if (btn) btn.disabled = false;
        if (btnText) btnText.innerText = 'Watch Ad to Unlock Video';
        if (btnIcon) btnIcon.innerHTML = '🎬';
        setStatus('Ready to try again', true);
    }

    // Startup
    document.addEventListener('DOMContentLoaded', () => {
        initTelegram();
        <?php if ($isValidToken && !$isAlreadyCompleted): ?>
            initMonetagAd();
        <?php endif; ?>
    });
</script>

</body>
</html>
