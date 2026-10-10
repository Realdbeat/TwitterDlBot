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

            CREATE INDEX IF NOT EXISTS idx_session_key ON tiktok_sessions(session_key);
            CREATE INDEX IF NOT EXISTS idx_video_session ON tiktok_session_videos(session_id, download_status);
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
}
