# 📥 Twitter (X) Video Downloader Telegram Bot (PHP)

A fast, lightweight, and zero-external-dependency Telegram bot built in pure **PHP 8** that allows users to send any Twitter / X video link and receive the playable video directly in chat.

---

## ✨ Features

- **Direct Video Delivery**: Sends the highest quality MP4 video available directly into the Telegram chat with native video player support.
- **Multiple URL Formats Supported**:
  - `https://x.com/username/status/1234567890`
  - `https://twitter.com/username/status/1234567890`
  - `https://mobile.twitter.com/...` or `https://m.twitter.com/...`
  - `https://x.com/i/status/1234567890`
  - Embed proxies: `fxtwitter.com`, `fixupx.com`, `vxtwitter.com`
  - Short links: `t.co/...` (auto-expanded)
- **Multi-Tier Video Extraction**: Uses redundant resolution engines (FxTwitter API, vxTwitter API, and direct CDN resolvers) for maximum uptime without needing Twitter developer keys.
- **Smart Upload Strategy**:
  1. *Instant URL Transfer*: Tries sending via direct stream URL first (zero server bandwidth consumption).
  2. *Chunked Local Buffer*: Automatically buffers to local storage and uploads via `multipart/form-data` if Telegram fails to fetch the remote URL.
  3. *Size Safeguard*: Respects Telegram's 50MB bot upload limit, offering direct fallback download links if exceeded.
- **Dual Execution Modes**:
  - **Long Polling (`bot.php`)**: Run locally or on any VPS without domain or SSL.
  - **Webhook (`webhook.php`)**: For production web servers (Nginx, Apache, Caddy).
- **Zero Heavy Dependencies**: Runs with standard PHP (cURL, JSON, mbstring). No Composer installation required (built-in PSR-4 autoloader included).

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
├── set_webhook.php       # CLI helper to manage webhook registration
├── composer.json         # Standard Composer package definition
├── tmp/                  # Temporary buffer directory (auto-created)
└── src/
    ├── TelegramBot.php       # Telegram Bot API client wrapper
    ├── TwitterDownloader.php # Twitter link parser & video resolver
    └── BotHandler.php        # Update router and message handler
```

---

## 🚀 Quick Start Guide

### 1. Requirements

- PHP **8.1** or higher
- PHP extensions: `curl`, `json`, `mbstring`, `fileinfo` (enabled by default in most PHP installations)

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

If you have a server with a public domain and HTTPS (e.g. Nginx/Apache):

1. Put the files in your web directory (e.g. `/var/www/twitterbot`).
2. Set your webhook URL in `.env`:
   ```env
   WEBHOOK_URL="https://yourdomain.com/webhook.php"
   WEBHOOK_SECRET="random_secure_secret_token"
   ```
3. Register the webhook with Telegram:
   ```bash
   php set_webhook.php set https://yourdomain.com/webhook.php
   ```
4. Check webhook status anytime:
   ```bash
   php set_webhook.php info
   ```
5. If you want to switch back to polling later:
   ```bash
   php set_webhook.php delete
   ```

---

## 🛠️ Keeping Long Polling Running in the Background

### Using Systemd (Linux VPS)
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

### Using PM2
```bash
pm2 start bot.php --name "twitter-dl-bot" --interpreter php
pm2 save
pm2 startup
```

### Using Screen or Tmux
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


https://api.telegram.org/8625947839:AAGtptNOl3ffq8uAQ9Br_YKmSMv41cWK0SA/setWebhook?url=https://xdlbot.mossubyte.com.ng/webhook.php
