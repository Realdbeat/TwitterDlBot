# 📥 Twitter (X) & TikTok Video Downloader Telegram Bot (PHP)

A fast, lightweight, and zero-external-dependency Telegram bot built in pure **PHP 8** that allows users to send any Twitter / X or TikTok video link and receive the playable, watermark-free video directly in chat.

---

## ✨ Features

- **Direct Video Delivery**: Sends the highest quality MP4 video available directly into the Telegram chat with native video player support.
- **Watermark-Free TikTok Downloads**: Automatically resolves HD and standard TikTok videos without watermarks, with one-tap background audio/MP3 extraction and photo slideshow support.
- **TikTok Profile Bulk Downloader**:
  - Send any creator's profile page link: `https://www.tiktok.com/@username`
  - Scrapes creator statistics and displays total video count.
  - Asks user permission to confirm before initiating the download.
  - Downloads videos in clean batches of **10 videos at a time**.
  - Provides interactive **`[⏩ Download Next 10]`** and **`[⏹️ Stop Download]`** buttons.
  - Remembers session progress using SQLite, ensuring zero duplicate downloads.
- **Multiple URL Formats Supported**:
  - **Twitter / X**:
    - `https://x.com/username/status/1234567890`
    - `https://twitter.com/username/status/1234567890`
    - `https://mobile.twitter.com/...` or `https://m.twitter.com/...`
    - Embed proxies: `fxtwitter.com`, `fixupx.com`, `vxtwitter.com`
    - Short links: `t.co/...` (auto-expanded)
  - **TikTok**:
    - `https://www.tiktok.com/@username` (Creator profile bulk download)
    - `https://www.tiktok.com/@username/video/1234567890` (Single video)
    - `https://www.tiktok.com/@username/photo/1234567890` (Photo carousels)
    - `https://m.tiktok.com/v/1234567890.html`
    - Short links: `vm.tiktok.com/...`, `vt.tiktok.com/...`, `tiktok.com/t/...` (auto-expanded)
- **Multi-Tier Video Extraction**: Uses redundant resolution engines (TikWM API, tnktok/fxTikTok, FxTwitter API, vxTwitter API, and direct CDN resolvers) for maximum uptime without needing API keys.
- **Smart Upload Strategy**:
  1. *Instant URL Transfer*: Tries sending via direct stream URL first (zero server bandwidth consumption).
  2. *Chunked Local Buffer*: Automatically buffers to local storage and uploads via `multipart/form-data` if Telegram fails to fetch the remote URL.
  3. *Size Safeguard*: Respects Telegram's 50MB bot upload limit, offering direct fallback download links if exceeded.
- **Dual Execution Modes**:
  - **Long Polling (`bot.php`)**: Run locally or on any VPS without domain or SSL.
  - **Webhook (`webhook.php`)**: For production web servers (Nginx, Apache, Caddy).
- **Zero Heavy Dependencies**: Runs with standard PHP (cURL, JSON, mbstring, PDO SQLite). No Composer installation required (built-in PSR-4 autoloader included).

---

## 📁 Project Structure

```
TwitterDlBot/
├── .env                  # Bot configuration & secrets
├── .env.example          # Environment template
├── config.php            # Environment loader & config manager
├── autoload.php          # Zero-dependency PSR-4 autoloader
├── bot.php               # Long-polling daemon (CLI)
├── webhook.php           # Webhook handler (HTTP POST)
├── log.php               # Live Web Monitor (Logs, IPs, Errors & Telegram status)
├── set_webhook.php       # CLI helper to manage webhook registration
├── composer.json         # Standard Composer package definition
├── tmp/                  # Temporary buffer & SQLite database directory
└── src/
    ├── TelegramBot.php       # Telegram Bot API client wrapper
    ├── TwitterDownloader.php # Twitter link parser & video resolver
    ├── TikTokDownloader.php  # TikTok watermark-free video & audio resolver
    ├── Database.php          # SQLite PDO session & video queue manager
    ├── BotHandler.php        # Update router and message handler
    └── Logger.php            # Activity, IP, User-Agent & error logger
```

---

## 🚀 Quick Start Guide

### 1. Requirements

- PHP **8.1** or higher
- PHP extensions: `curl`, `json`, `mbstring`, `fileinfo`, `pdo_sqlite` (enabled by default in most PHP installations)

Check your PHP version:
```bash
php -v
```

---

### 2. Get a Telegram Bot Token

1. Open Telegram and search for [@BotFather](https://t.me/BotFather).
2. Send `/newbot` and follow the prompts to choose a bot name and username (e.g., `MyTwitterVideoDlBot`).
3. Copy the HTTP API token provided by BotFather (looks like `123456789:ABCdefGHIjklMNOpqrSTUvwxYZ`).

---

### 3. Configure the Bot

Open the [.env](file:///c:/Users/OnlyGods/CodeBases/TwitterDlBot/.env) file and paste your token:

```env
TELEGRAM_BOT_TOKEN="123456789:ABCdefGHIjklMNOpqrSTUvwxYZ"
```

Optional settings in `.env`:
- `MAX_FILE_SIZE_MB=50` (Telegram's bot API limit is 50MB)
- `TEMP_DIR="./tmp"`
- `HTTP_TIMEOUT=60`

---

### 4. Run the Bot (Long Polling Mode)

Start the bot daemon in your terminal:

```bash
php bot.php
```

You will see:
```text
===============================================
   Twitter Video Downloader Telegram Bot       
===============================================

[2026-10-09 04:15:00] [INFO] Testing connection to Telegram Bot API...
[2026-10-09 04:15:01] [INFO] Logged in as @YourBot (Bot Name) [ID: 123456789]
[2026-10-09 04:15:01] [INFO] Removing any existing webhook...
[2026-10-09 04:15:01] [INFO] Bot is active and polling for updates! Press Ctrl+C to stop.
```

Now open Telegram, message your bot `/start`, send any Twitter/X video link, and receive the downloaded video!

---

## 🌐 Running in Webhook Mode (Production)

If you have a web server with a public domain and an SSL certificate (HTTPS):

### Step 1: Upload Files to Server
Place all the bot files in your public web directory (e.g. `public_html/` or `/var/www/twitterbot`).

### Step 2: Configure Environment
Make sure your `.env` file on the server has your bot token:
```env
TELEGRAM_BOT_TOKEN="your_bot_token_from_botfather"
WEBHOOK_URL="https://yourdomain.com/webhook.php"
WEBHOOK_SECRET="optional_random_secret_token"
```

---

### Step 3: Register the Webhook With Telegram

Choose any of the following methods to activate your webhook:

#### Method A: Directly via Browser (Fastest)
Replace `<YOUR_BOT_TOKEN>` and your domain, then open this URL in your browser:
```text
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php
```

**Real Example:**
```text
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://xdlbot.mossubyte.com.ng/webhook.php
```

You will see Telegram's confirmation response:
```json
{
  "ok": true,
  "result": true,
  "description": "Webhook was set"
}
```

#### Method B: Using the CLI Tool (`set_webhook.php`)
Run this command from your project root in the server terminal:
```bash
php set_webhook.php set https://yourdomain.com/webhook.php
```

#### Method C: Using cURL
```bash
curl -F "url=https://yourdomain.com/webhook.php" https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook
```

---

### Step 4: Verify Webhook Status

To check if Telegram is successfully delivering messages to your webhook:

**Via Browser:**
```text
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo
```

**Via CLI:**
```bash
php set_webhook.php info
```

Look for:
- `"url"`: Should point to your `https://.../webhook.php`
- `"pending_update_count"`: `0` (means all updates are being delivered immediately)
- `"last_error_message"`: None

---

### How to Delete / Unregister Webhook
If you ever want to switch back to local Long Polling (`php bot.php`), delete the webhook first:

**Via Browser:**
```text
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/deleteWebhook
```

**Via CLI:**
```bash
php set_webhook.php delete
```

---

### ⚠️ Webhook Troubleshooting & Common Gotchas

1. **Telegram reports `"Wrong response from the webhook: 403 Forbidden"`**:
   - This occurs when your hosting provider's web server firewall (**ModSecurity** / cPanel security rules) intercepts Telegram's POST requests before they reach `webhook.php`.
   - **Solution A**: Upload the provided [`.htaccess`](file:///c:/Users/OnlyGods/CodeBases/TwitterDlBot/.htaccess) file to your server root (it tells Apache to disable ModSecurity inspection for your webhook script).
   - **Solution B (cPanel)**: Log into cPanel, search for **ModSecurity**, find `xdlbot.mossubyte.com.ng`, and toggle it to **Off**.
   - **Solution C (Cloudflare)**: If your domain uses Cloudflare, go to **Security > WAF** and create an allow rule for Telegram's IP ranges or path `/webhook.php`.

2. **Opening `webhook.php` in a browser**:
   - Opening the URL in a browser sends an HTTP `GET` request. Telegram sends bot events using `POST`. Our script displays a friendly health check status when visited via `GET`.

3. **HTTPS Required**:
   - Telegram Bot API strictly requires a valid SSL/TLS certificate (HTTPS). Self-signed or HTTP connections are rejected.

4. **Writable `tmp/` Directory**:
   - Ensure PHP has write permissions to create temporary files in `./tmp` for video buffer uploads.

---

## 📊 Live Activity, IP & Error Monitor (`log.php`)

Visit `https://yourdomain.com/log.php` in any web browser to open the real-time activity and diagnostics dashboard:

- **Client IP Tracking**: Accurately records visitor and Telegram Bot API server IPs (supports Cloudflare, Reverse Proxies, and direct connections).
- **User-Agent Inspector**: Clearly displays incoming `User-Agent` strings and highlights `[EMPTY/MISSING]` headers so you can easily spot if ModSecurity or WAF blocked a request.
- **Telegram Webhook Health Card**: Live check against Telegram's Bot API displaying your active webhook URL, pending update queue count, and any recent error messages.
- **Detailed Inspection**: Click any row to view full incoming update payloads, JSON bodies, and error stack traces.
- **Live Auto-Refresh**: Automatically polls for new events without reloading the page.
- **Security**: To restrict access, simply set `LOG_PASSWORD="your_password"` in `.env`.

---

## 🚀 Running on Shared Hosting (cPanel / Apache) vs VPS

### 🌟 If You Are on Shared Hosting (cPanel):
On shared hosting, **you do NOT need Systemd, PM2, or any background daemon at all!**

Shared hosting is designed to use **Webhook Mode (`webhook.php`)**:
1. Simply upload the files to your domain directory (`public_html` or subdomain folder).
2. Register your webhook once with Telegram (see [Webhook Mode](#-running-in-webhook-mode-production) above).
3. **That's it!** You don't need any terminal or process manager running. Every time a user sends a link, Telegram automatically invokes `webhook.php`, which processes the request and sends the video 24/7.

*(Optional)* If you run polling on shared hosting instead of webhooks, you can use **cPanel Cron Jobs**:

**Option A (Recommended — Web Cron via curl):**
- In **cPanel > Cron Jobs**, set schedule to every minute (`* * * * *`):
  ```bash
  curl -s "https://yourdomain.com/poll.php" >/dev/null 2>&1
  ```
  *(Uses your server's web PHP 8.1 engine, immune to CLI PHP version conflicts and background process kills)*.

**Option B (CLI Cron with ea-php81):**
- In **cPanel > Cron Jobs**, set:
  ```bash
  /usr/local/bin/ea-php81 /home/username/public_html/bot.php --cron >/dev/null 2>&1
  ```
  *(Always specify `/usr/local/bin/ea-php81` on cPanel instead of plain `/usr/local/bin/php` to guarantee PHP 8.1+ execution)*.
*(Note: Webhook mode is strongly recommended over Cron for shared hosting.)*

---

### 🖥️ If You Are on a VPS / Dedicated Server (Systemd / PM2)

If you are running the Long-Polling daemon (`php bot.php`) on a Linux VPS:

#### Using Systemd
Create `/etc/systemd/system/twitter-bot.service`:
```ini
[Unit]
Description=Twitter Video Downloader Telegram Bot
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/TwitterDlBot
ExecStart=/usr/bin/php /path/to/TwitterDlBot/bot.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now twitter-bot
sudo journalctl -u twitter-bot -f
```

#### Using PM2
```bash
pm2 start bot.php --name "twitter-dl-bot" --interpreter php
pm2 save
pm2 startup
```

#### Using Screen or Tmux
```bash
screen -S twitterbot
php bot.php
# Press Ctrl+A then D to detach
```

---

## 💡 How It Works Under The Hood

```
[ User sends X.com / Twitter link ]
                │
                ▼
      [ Telegram Bot API ]
                │
                ▼
        [ BotHandler.php ]
  Extracts tweet ID and username
                │
                ▼
    [ TwitterDownloader.php ]
  Queries multi-tier metadata resolvers:
  1. FxTwitter API
  2. vxTwitter API
  3. Direct twimg redirect
                │
                ▼
  Highest-bitrate MP4 stream resolved
                │
        ┌───────┴───────┐
        ▼               ▼
[ Send by URL ]   [ Chunked Download ]
  (Fast, 0 band)    (If remote URL blocked)
        │               │
        └───────┬───────┘
                ▼
     [ Native Video Message ]
  Sent with caption & author info
```

---

## 📄 License

MIT License. Free to use and modify for personal or commercial projects.
