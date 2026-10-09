<?php
namespace TwitterDlBot;

use CURLFile;

/**
 * Telegram Bot API Client
 */
class TelegramBot {
    private string $token;
    private string $apiUrl;
    private int $timeout;

    public function __construct(string $token, int $timeout = 60) {
        if (empty($token) || $token === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
            throw new \InvalidArgumentException('Telegram Bot Token is not configured. Please set TELEGRAM_BOT_TOKEN in .env');
        }
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}/";
        $this->timeout = $timeout;
    }

    /**
     * Executes a call to the Telegram Bot API.
     */
    public function request(string $method, array $params = [], bool $isMultipart = false): array {
        $ch = curl_init($this->apiUrl . $method);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json'
                ]);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'error_code' => 0,
                'description' => "cURL network error: {$error}"
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error_code' => $httpCode,
                'description' => "Invalid JSON response from Telegram API: {$response}"
            ];
        }

        return $decoded;
    }

    /**
     * Get information about the bot account.
     */
    public function getMe(): array {
        return $this->request('getMe');
    }

    /**
     * Send a text message to a user or chat.
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ], $options);

        return $this->request('sendMessage', $params);
    }

    /**
     * Edit an existing message text.
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $options = []): array {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ], $options);

        return $this->request('editMessageText', $params);
    }

    /**
     * Delete a message.
     */
    public function deleteMessage(int|string $chatId, int $messageId): array {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    /**
     * Send a chat action status (e.g. upload_video, typing).
     */
    public function sendChatAction(int|string $chatId, string $action = 'upload_video'): array {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action
        ]);
    }

    /**
     * Send a video file (either by URL, file_id, or local CURLFile upload).
     */
    public function sendVideo(int|string $chatId, mixed $video, array $options = []): array {
        $isMultipart = ($video instanceof CURLFile);
        $params = array_merge([
            'chat_id' => $chatId,
            'video' => $video,
            'parse_mode' => 'HTML',
            'supports_streaming' => true
        ], $options);

        // If options contain thumbnail as CURLFile, it must be multipart
        if (isset($params['thumb']) && $params['thumb'] instanceof CURLFile) {
            $isMultipart = true;
        }

        return $this->request('sendVideo', $params, $isMultipart);
    }

    /**
     * Send a document (fallback for video sending).
     */
    public function sendDocument(int|string $chatId, mixed $document, array $options = []): array {
        $isMultipart = ($document instanceof CURLFile);
        $params = array_merge([
            'chat_id' => $chatId,
            'document' => $document,
            'parse_mode' => 'HTML'
        ], $options);

        return $this->request('sendDocument', $params, $isMultipart);
    }

    /**
     * Send a photo (either by URL, file_id, or local CURLFile upload).
     */
    public function sendPhoto(int|string $chatId, mixed $photo, array $options = []): array {
        $isMultipart = ($photo instanceof CURLFile);
        $params = array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
            'parse_mode' => 'HTML'
        ], $options);

        return $this->request('sendPhoto', $params, $isMultipart);
    }

    /**
     * Send an audio file (either by URL, file_id, or local CURLFile upload).
     */
    public function sendAudio(int|string $chatId, mixed $audio, array $options = []): array {
        $isMultipart = ($audio instanceof CURLFile);
        $params = array_merge([
            'chat_id' => $chatId,
            'audio' => $audio,
            'parse_mode' => 'HTML'
        ], $options);

        return $this->request('sendAudio', $params, $isMultipart);
    }

    /**
     * Send a group of media items (e.g. multiple videos).
     */
    public function sendMediaGroup(int|string $chatId, array $media): array {
        return $this->request('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($media)
        ]);
    }

    /**
     * Answer an incoming callback query.
     */
    public function answerCallbackQuery(string $callbackQueryId, array $options = []): array {
        $params = array_merge([
            'callback_query_id' => $callbackQueryId
        ], $options);

        return $this->request('answerCallbackQuery', $params);
    }

    /**
     * Fetch updates using Long Polling.
     */
    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 30): array {
        return $this->request('getUpdates', [
            'offset' => $offset,
            'limit' => $limit,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query']
        ]);
    }

    /**
     * Set a webhook URL for receiving updates.
     */
    public function setWebhook(string $url, array $options = []): array {
        $params = array_merge([
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query']
        ], $options);

        return $this->request('setWebhook', $params);
    }

    /**
     * Remove the webhook (required before using long polling).
     */
    public function deleteWebhook(bool $dropPendingUpdates = false): array {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates
        ]);
    }

    /**
     * Get current webhook status.
     */
    public function getWebhookInfo(): array {
        return $this->request('getWebhookInfo');
    }
}
