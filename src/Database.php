<?php
namespace TwitterDlBot;

use PDO;
use PDOException;

/**
 * SQLite Database Manager for Download Sessions & Queues
 */
class Database {
    private PDO $pdo;

    public function __construct(string $dbDir) {
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }

        $dbPath = rtrim($dbDir, '/\\') . DIRECTORY_SEPARATOR . 'bot_database.sqlite';
        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->initSchema();
    }

    /**
     * Initializes SQLite tables.
     */
    private function initSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tiktok_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_key TEXT UNIQUE,
                chat_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                username TEXT NOT NULL,
                nickname TEXT NOT NULL,
                total_videos INTEGER DEFAULT 0,
                downloaded_count INTEGER DEFAULT 0,
                status TEXT DEFAULT 'pending_permission',
                pagination_state TEXT,
                has_more INTEGER DEFAULT 1,
                created_at INTEGER,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS tiktok_session_videos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                video_id TEXT NOT NULL,
                title TEXT,
                url TEXT,
                download_status TEXT DEFAULT 'pending',
                created_at INTEGER,
                UNIQUE(session_id, video_id),
                FOREIGN KEY(session_id) REFERENCES tiktok_sessions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS pending_ad_downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE NOT NULL,
                chat_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                link_type TEXT NOT NULL,
                link_data TEXT NOT NULL,
                reply_to_message_id INTEGER,
                status_message_id INTEGER,
                status TEXT DEFAULT 'pending',
                created_at INTEGER,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS bot_users (
                user_id TEXT PRIMARY KEY,
                chat_id TEXT NOT NULL,
                username TEXT,
                first_name TEXT,
                premium_until INTEGER DEFAULT 0,
                referred_by TEXT DEFAULT NULL,
                referral_count INTEGER DEFAULT 0,
                created_at INTEGER,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS bot_referrals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_id TEXT NOT NULL,
                referred_id TEXT NOT NULL UNIQUE,
                reward_days INTEGER DEFAULT 1,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS bot_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                chat_id TEXT NOT NULL,
                telegram_payment_charge_id TEXT,
                provider_payment_charge_id TEXT,
                invoice_payload TEXT,
                currency TEXT,
                total_amount INTEGER,
                duration_days INTEGER,
                created_at INTEGER
            );

            CREATE INDEX IF NOT EXISTS idx_session_key ON tiktok_sessions(session_key);
            CREATE INDEX IF NOT EXISTS idx_video_session ON tiktok_session_videos(session_id, download_status);
            CREATE INDEX IF NOT EXISTS idx_ad_token ON pending_ad_downloads(token);
            CREATE INDEX IF NOT EXISTS idx_ad_status ON pending_ad_downloads(status);
            CREATE INDEX IF NOT EXISTS idx_user_premium ON bot_users(premium_until);
            CREATE INDEX IF NOT EXISTS idx_ref_referrer ON bot_referrals(referrer_id);
            CREATE INDEX IF NOT EXISTS idx_payment_user ON bot_payments(user_id);
        ");
    }

    /**
     * Creates a new user profile download session.
     */
    public function createSession(
        string $sessionKey,
        int|string $chatId,
        int|string $userId,
        string $username,
        string $nickname,
        int $totalVideos,
        ?array $paginationState = null,
        bool $hasMore = true
    ): int {
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO tiktok_sessions (
                session_key, chat_id, user_id, username, nickname, 
                total_videos, downloaded_count, status, pagination_state, has_more, created_at, updated_at
            ) VALUES (
                :session_key, :chat_id, :user_id, :username, :nickname,
                :total_videos, 0, 'pending_permission', :pagination_state, :has_more, :created_at, :updated_at
            )
        ");

        $stmt->execute([
            ':session_key' => $sessionKey,
            ':chat_id' => (string)$chatId,
            ':user_id' => (string)$userId,
            ':username' => $username,
            ':nickname' => $nickname,
            ':total_videos' => $totalVideos,
            ':pagination_state' => $paginationState ? json_encode($paginationState) : null,
            ':has_more' => $hasMore ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Retrieves session details by key.
     */
    public function getSession(string $sessionKey): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM tiktok_sessions WHERE session_key = :key LIMIT 1");
        $stmt->execute([':key' => $sessionKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (!empty($row['pagination_state'])) {
            $row['pagination_state_array'] = json_decode($row['pagination_state'], true);
        } else {
            $row['pagination_state_array'] = null;
        }

        return $row;
    }

    /**
     * Updates session status.
     */
    public function updateSessionStatus(string $sessionKey, string $status): void {
        $stmt = $this->pdo->prepare("
            UPDATE tiktok_sessions 
            SET status = :status, updated_at = :updated_at 
            WHERE session_key = :key
        ");
        $stmt->execute([
            ':status' => $status,
            ':updated_at' => time(),
            ':key' => $sessionKey
        ]);
    }

    /**
     * Updates session pagination state.
     */
    public function updateSessionPagination(string $sessionKey, ?array $state, bool $hasMore): void {
        $stmt = $this->pdo->prepare("
            UPDATE tiktok_sessions 
            SET pagination_state = :state, has_more = :has_more, updated_at = :updated_at 
            WHERE session_key = :key
        ");
        $stmt->execute([
            ':state' => $state ? json_encode($state) : null,
            ':has_more' => $hasMore ? 1 : 0,
            ':updated_at' => time(),
            ':key' => $sessionKey
        ]);
    }

    /**
     * Increments the downloaded count for a session.
     */
    public function incrementSessionDownloaded(string $sessionKey, int $count = 1): void {
        $stmt = $this->pdo->prepare("
            UPDATE tiktok_sessions 
            SET downloaded_count = downloaded_count + :cnt, updated_at = :updated_at 
            WHERE session_key = :key
        ");
        $stmt->execute([
            ':cnt' => $count,
            ':updated_at' => time(),
            ':key' => $sessionKey
        ]);
    }

    /**
     * Adds videos to a session queue (ignoring duplicates).
     */
    public function addVideosToSession(int $sessionId, array $videos): int {
        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO tiktok_session_videos (
                session_id, video_id, title, url, download_status, created_at
            ) VALUES (
                :session_id, :video_id, :title, :url, 'pending', :created_at
            )
        ");

        $added = 0;
        $now = time();
        foreach ($videos as $v) {
            $stmt->execute([
                ':session_id' => $sessionId,
                ':video_id' => $v['id'],
                ':title' => $v['title'] ?? '',
                ':url' => $v['url'] ?? '',
                ':created_at' => $now
            ]);
            if ($stmt->rowCount() > 0) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Fetches up to $limit pending videos for a session.
     */
    public function getPendingVideos(int $sessionId, int $limit = 10): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM tiktok_session_videos 
            WHERE session_id = :session_id AND download_status = 'pending' 
            ORDER BY id ASC 
            LIMIT :limit
        ");
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Marks a video as downloaded.
     */
    public function markVideoDownloaded(int $videoId): void {
        $stmt = $this->pdo->prepare("UPDATE tiktok_session_videos SET download_status = 'downloaded' WHERE id = :id");
        $stmt->execute([':id' => $videoId]);
    }

    /**
     * Marks a video as failed.
     */
    public function markVideoFailed(int $videoId): void {
        $stmt = $this->pdo->prepare("UPDATE tiktok_session_videos SET download_status = 'failed' WHERE id = :id");
        $stmt->execute([':id' => $videoId]);
    }

    /**
     * Counts pending videos remaining in the queue.
     */
    public function countPendingVideos(int $sessionId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt 
            FROM tiktok_session_videos 
            WHERE session_id = :session_id AND download_status = 'pending'
        ");
        $stmt->execute([':session_id' => $sessionId]);
        $row = $stmt->fetch();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Counts successfully downloaded videos.
     */
    public function countDownloadedVideos(int $sessionId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt 
            FROM tiktok_session_videos 
            WHERE session_id = :session_id AND download_status = 'downloaded'
        ");
        $stmt->execute([':session_id' => $sessionId]);
        $row = $stmt->fetch();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Stores a pending video download that requires watching a Monetag ad.
     */
    public function createPendingAdDownload(
        string $token,
        int|string $chatId,
        int|string $userId,
        string $linkType,
        array $linkData,
        ?int $replyToMessageId = null,
        ?int $statusMessageId = null
    ): int {
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO pending_ad_downloads (
                token, chat_id, user_id, link_type, link_data,
                reply_to_message_id, status_message_id, status, created_at, updated_at
            ) VALUES (
                :token, :chat_id, :user_id, :link_type, :link_data,
                :reply_to, :status_msg, 'pending', :created_at, :updated_at
            )
        ");

        $stmt->execute([
            ':token' => $token,
            ':chat_id' => (string)$chatId,
            ':user_id' => (string)$userId,
            ':link_type' => $linkType,
            ':link_data' => json_encode($linkData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':reply_to' => $replyToMessageId,
            ':status_msg' => $statusMessageId,
            ':created_at' => $now,
            ':updated_at' => $now
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Updates the status message ID for a pending ad download.
     */
    public function updatePendingAdStatusMessage(string $token, int $messageId): void {
        $stmt = $this->pdo->prepare("
            UPDATE pending_ad_downloads 
            SET status_message_id = :mid, updated_at = :up 
            WHERE token = :token
        ");
        $stmt->execute([
            ':mid' => $messageId,
            ':up' => time(),
            ':token' => $token
        ]);
    }

    /**
     * Retrieves a pending ad download record by its token.
     */
    public function getPendingAdDownload(string $token): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM pending_ad_downloads WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['link_data_array'] = json_decode($row['link_data'] ?? '[]', true) ?: [];
        return $row;
    }

    /**
     * Updates the status of a pending ad download.
     */
    public function updatePendingAdStatus(string $token, string $status): void {
        $stmt = $this->pdo->prepare("
            UPDATE pending_ad_downloads 
            SET status = :status, updated_at = :updated_at 
            WHERE token = :token
        ");
        $stmt->execute([
            ':status' => $status,
            ':updated_at' => time(),
            ':token' => $token
        ]);
    }

    /**
     * Cleans up expired pending ad requests (older than $maxAgeSeconds).
     */
    public function cleanupOldPendingAds(int $maxAgeSeconds = 86400): int {
        $cutoff = time() - $maxAgeSeconds;
        $stmt = $this->pdo->prepare("DELETE FROM pending_ad_downloads WHERE created_at < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Upserts a bot user and tracks activity.
     */
    public function upsertUser(
        int|string $userId,
        int|string $chatId,
        ?string $username = null,
        ?string $firstName = null
    ): array {
        $userIdStr = (string)$userId;
        $chatIdStr = (string)$chatId;
        $now = time();

        $stmt = $this->pdo->prepare("SELECT * FROM bot_users WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userIdStr]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmtUpdate = $this->pdo->prepare("
                UPDATE bot_users 
                SET chat_id = :cid, 
                    username = COALESCE(:uname, username), 
                    first_name = COALESCE(:fname, first_name), 
                    updated_at = :up
                WHERE user_id = :uid
            ");
            $stmtUpdate->execute([
                ':cid' => $chatIdStr,
                ':uname' => $username,
                ':fname' => $firstName,
                ':up' => $now,
                ':uid' => $userIdStr
            ]);
            $existing['chat_id'] = $chatIdStr;
            if ($username) $existing['username'] = $username;
            if ($firstName) $existing['first_name'] = $firstName;
            return $existing;
        }

        $stmtInsert = $this->pdo->prepare("
            INSERT INTO bot_users (user_id, chat_id, username, first_name, premium_until, referred_by, referral_count, created_at, updated_at)
            VALUES (:uid, :cid, :uname, :fname, 0, NULL, 0, :now, :now)
        ");
        $stmtInsert->execute([
            ':uid' => $userIdStr,
            ':cid' => $chatIdStr,
            ':uname' => $username,
            ':fname' => $firstName,
            ':now' => $now
        ]);

        return [
            'user_id' => $userIdStr,
            'chat_id' => $chatIdStr,
            'username' => $username,
            'first_name' => $firstName,
            'premium_until' => 0,
            'referred_by' => null,
            'referral_count' => 0,
            'created_at' => $now,
            'updated_at' => $now
        ];
    }

    /**
     * Retrieves user profile and premium status.
     */
    public function getUser(int|string $userId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM bot_users WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => (string)$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Checks if user has an active premium subscription.
     */
    public function isUserPremium(int|string $userId): bool {
        $stmt = $this->pdo->prepare("SELECT premium_until FROM bot_users WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => (string)$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        return (int)($row['premium_until'] ?? 0) > time();
    }

    /**
     * Adds premium days to a user (stacks on existing active time or starts from now).
     */
    public function addPremiumDays(int|string $userId, int $days): int {
        $userIdStr = (string)$userId;
        $now = time();
        $secondsToAdd = $days * 86400;

        $user = $this->getUser($userIdStr);
        $currentUntil = (int)($user['premium_until'] ?? 0);

        if ($currentUntil > $now) {
            $newUntil = $currentUntil + $secondsToAdd;
        } else {
            $newUntil = $now + $secondsToAdd;
        }

        $stmt = $this->pdo->prepare("
            UPDATE bot_users 
            SET premium_until = :until, updated_at = :up 
            WHERE user_id = :uid
        ");
        $stmt->execute([
            ':until' => $newUntil,
            ':up' => $now,
            ':uid' => $userIdStr
        ]);

        return $newUntil;
    }

    /**
     * Processes referral reward when a new user registers with a referrer code.
     * Returns reward metadata if the referral was valid and processed.
     */
    public function processReferral(
        int|string $newUserId,
        int|string $newChatId,
        int|string $referrerId,
        ?string $username = null,
        ?string $firstName = null
    ): ?array {
        $newUserIdStr = (string)$newUserId;
        $newChatIdStr = (string)$newChatId;
        $referrerIdStr = (string)$referrerId;

        // Cannot refer oneself
        if ($newUserIdStr === $referrerIdStr) {
            return null;
        }

        // Referrer must exist in database
        $referrer = $this->getUser($referrerIdStr);
        if (!$referrer) {
            return null;
        }

        // Check if user has already been recorded
        $stmtCheck = $this->pdo->prepare("SELECT user_id FROM bot_users WHERE user_id = :uid LIMIT 1");
        $stmtCheck->execute([':uid' => $newUserIdStr]);
        if ($stmtCheck->fetch()) {
            return null;
        }

        // Check if already in referrals table
        $stmtRefCheck = $this->pdo->prepare("SELECT id FROM bot_referrals WHERE referred_id = :uid LIMIT 1");
        $stmtRefCheck->execute([':uid' => $newUserIdStr]);
        if ($stmtRefCheck->fetch()) {
            return null;
        }

        $now = time();

        // Register new user with referred_by
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO bot_users (user_id, chat_id, username, first_name, premium_until, referred_by, referral_count, created_at, updated_at)
            VALUES (:uid, :cid, :uname, :fname, 0, :refby, 0, :now, :now)
        ");
        $stmtInsert->execute([
            ':uid' => $newUserIdStr,
            ':cid' => $newChatIdStr,
            ':uname' => $username,
            ':fname' => $firstName,
            ':refby' => $referrerIdStr,
            ':now' => $now
        ]);

        // Record referral entry
        $stmtRef = $this->pdo->prepare("
            INSERT INTO bot_referrals (referrer_id, referred_id, reward_days, created_at)
            VALUES (:ref, :newu, 1, :now)
        ");
        $stmtRef->execute([
            ':ref' => $referrerIdStr,
            ':newu' => $newUserIdStr,
            ':now' => $now
        ]);

        // Award +1 day of premium to referrer
        $newPremiumUntil = $this->addPremiumDays($referrerIdStr, 1);

        // Increment referrer count
        $stmtInc = $this->pdo->prepare("
            UPDATE bot_users 
            SET referral_count = referral_count + 1, updated_at = :now 
            WHERE user_id = :uid
        ");
        $stmtInc->execute([':now' => $now, ':uid' => $referrerIdStr]);

        $updatedReferrer = $this->getUser($referrerIdStr);

        return [
            'referrer_id' => $referrerIdStr,
            'referrer_chat_id' => $referrer['chat_id'],
            'new_user_id' => $newUserIdStr,
            'new_premium_until' => $newPremiumUntil,
            'referral_count' => (int)($updatedReferrer['referral_count'] ?? 1)
        ];
    }

    /**
     * Records a Telegram Stars payment and activates premium duration.
     */
    public function recordPayment(
        int|string $userId,
        int|string $chatId,
        string $chargeId,
        string $providerChargeId,
        string $payload,
        string $currency,
        int $amount,
        int $durationDays
    ): int {
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO bot_payments (
                user_id, chat_id, telegram_payment_charge_id, provider_payment_charge_id,
                invoice_payload, currency, total_amount, duration_days, created_at
            ) VALUES (
                :uid, :cid, :charge_id, :prov_charge_id,
                :payload, :currency, :amount, :days, :now
            )
        ");
        $stmt->execute([
            ':uid' => (string)$userId,
            ':cid' => (string)$chatId,
            ':charge_id' => $chargeId,
            ':prov_charge_id' => $providerChargeId,
            ':payload' => $payload,
            ':currency' => $currency,
            ':amount' => $amount,
            ':days' => $durationDays,
            ':now' => $now
        ]);

        // Add the premium duration
        $newUntil = $this->addPremiumDays($userId, $durationDays);

        return $newUntil;
    }
}
