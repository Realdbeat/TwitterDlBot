<?php
namespace TwitterDlBot;

/**
 * Twitter / X Video Media Resolver & Downloader
 */
class TwitterDownloader {
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 (TelegramTwitterDlBot/1.0)';
    private int $httpTimeout;

    public function __construct(int $httpTimeout = 30) {
        $this->httpTimeout = $httpTimeout;
    }

    /**
     * Extracts tweet IDs, usernames, and URLs from a message text.
     * Handles twitter.com, x.com, mobile links, proxy embeds, and t.co short links.
     *
     * @return array<int, array{id: string, user: string, url: string}>
     */
    public function extractTweetLinks(string $text): array {
        // Expand t.co links first if any
        if (preg_match_all('/https?:\/\/t\.co\/[a-zA-Z0-9]+/i', $text, $tcoMatches)) {
            foreach (array_unique($tcoMatches[0]) as $tcoUrl) {
                $expanded = $this->resolveRedirectUrl($tcoUrl);
                if ($expanded && $expanded !== $tcoUrl) {
                    $text .= ' ' . $expanded;
                }
            }
        }

        $pattern = '/https?:\/\/(?:www\.|mobile\.|m\.)?(?:twitter\.com|x\.com|fxtwitter\.com|vxtwitter\.com|fixupx\.com)\/(?:#!\/)?([a-zA-Z0-9_]{1,20}|i)\/status(?:es)?\/([0-9]+)(?:\S*)?/i';
        
        $matches = [];
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $results = [];
        $seen = [];
        foreach ($matches as $m) {
            $user = ($m[1] === 'i' || empty($m[1])) ? 'Twitter' : $m[1];
            $id = $m[2];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $results[] = [
                'id' => $id,
                'user' => $user,
                'url' => $m[0]
            ];
        }

        return $results;
    }

    /**
     * Fetches tweet metadata and resolves video stream URLs.
     */
    public function getVideoInfo(string $id, string $user = 'Twitter'): ?array {
        // Method 1: FixTweet API (api.fxtwitter.com)
        $info = $this->fetchFromFxTwitter($id, $user);
        if ($info !== null) {
            return $info;
        }

        // Method 2: vxTwitter API (api.vxtwitter.com)
        $info = $this->fetchFromVxTwitter($id, $user);
        if ($info !== null) {
            return $info;
        }

        // Method 3: Direct media redirect via fixupx / fxtwitter
        $info = $this->fetchFromDirectRedirect($id, $user);
        if ($info !== null) {
            return $info;
        }

        return null;
    }

    /**
     * Resolves metadata via FxTwitter / FixTweet API.
     */
    private function fetchFromFxTwitter(string $id, string $user): ?array {
        $endpoints = [
            "https://api.fxtwitter.com/status/{$id}",
            "https://api.fxtwitter.com/{$user}/status/{$id}"
        ];

        foreach ($endpoints as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_TIMEOUT => $this->httpTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200 || !$response) {
                continue;
            }

            $data = json_decode($response, true);
            if (!isset($data['tweet']) || empty($data['tweet'])) {
                continue;
            }

            $tweet = $data['tweet'];
            $videos = [];

            // Extract videos
            if (isset($tweet['media'])) {
                if (!empty($tweet['media']['videos'])) {
                    foreach ($tweet['media']['videos'] as $v) {
                        $videoUrl = $v['url'] ?? null;
                        if (!$videoUrl && !empty($v['variants'])) {
                            $videoUrl = $this->pickBestVariant($v['variants']);
                        }
                        if ($videoUrl) {
                            $videos[] = [
                                'url' => $videoUrl,
                                'type' => $v['type'] ?? 'video',
                                'thumbnail' => $v['thumbnail_url'] ?? null,
                                'width' => $v['width'] ?? null,
                                'height' => $v['height'] ?? null,
                                'duration' => $v['duration'] ?? null
                            ];
                        }
                    }
                }

                if (empty($videos) && !empty($tweet['media']['all'])) {
                    foreach ($tweet['media']['all'] as $item) {
                        if (in_array($item['type'] ?? '', ['video', 'gif'])) {
                            $videoUrl = $item['url'] ?? null;
                            if (!$videoUrl && !empty($item['variants'])) {
                                $videoUrl = $this->pickBestVariant($item['variants']);
                            }
                            if ($videoUrl) {
                                $videos[] = [
                                    'url' => $videoUrl,
                                    'type' => $item['type'],
                                    'thumbnail' => $item['thumbnail_url'] ?? null,
                                    'width' => $item['width'] ?? null,
                                    'height' => $item['height'] ?? null,
                                    'duration' => $item['duration'] ?? null
                                ];
                            }
                        }
                    }
                }
            }

            $hasPhotos = false;
            if (!empty($tweet['media']['photos'])) {
                $hasPhotos = true;
            }

            return [
                'id' => $tweet['id'] ?? $id,
                'author_name' => $tweet['author']['name'] ?? 'Twitter User',
                'author_handle' => $tweet['author']['screen_name'] ?? $user,
                'text' => $tweet['text'] ?? '',
                'url' => $tweet['url'] ?? "https://x.com/{$user}/status/{$id}",
                'likes' => $tweet['likes'] ?? 0,
                'retweets' => $tweet['retweets'] ?? 0,
                'created_at' => $tweet['created_at'] ?? null,
                'videos' => $videos,
                'has_media' => !empty($tweet['media']),
                'has_video' => !empty($videos),
                'has_photos' => $hasPhotos
            ];
        }

        return null;
    }

    /**
     * Resolves metadata via vxTwitter API.
     */
    private function fetchFromVxTwitter(string $id, string $user): ?array {
        $url = "https://api.vxtwitter.com/Twitter/status/{$id}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || (!isset($data['mediaURLs']) && !isset($data['media_extended']))) {
            return null;
        }

        $videos = [];
        if (!empty($data['media_extended'])) {
            foreach ($data['media_extended'] as $m) {
                if (($m['type'] ?? '') === 'video' || ($m['type'] ?? '') === 'gif') {
                    $videos[] = [
                        'url' => $m['url'],
                        'type' => $m['type'],
                        'thumbnail' => $m['thumbnail_url'] ?? null,
                        'width' => $m['size']['width'] ?? null,
                        'height' => $m['size']['height'] ?? null,
                        'duration' => !empty($m['duration_millis']) ? round($m['duration_millis'] / 1000) : null
                    ];
                }
            }
        } elseif (!empty($data['mediaURLs'])) {
            foreach ($data['mediaURLs'] as $mUrl) {
                if (preg_match('/\.mp4(\?.*)?$/i', $mUrl) || str_contains($mUrl, 'video.twimg.com')) {
                    $videos[] = [
                        'url' => $mUrl,
                        'type' => 'video',
                        'thumbnail' => null,
                        'width' => null,
                        'height' => null,
                        'duration' => null
                    ];
                }
            }
        }

        return [
            'id' => $id,
            'author_name' => $data['user_name'] ?? 'Twitter User',
            'author_handle' => $data['user_screen_name'] ?? $user,
            'text' => $data['text'] ?? '',
            'url' => $data['tweetURL'] ?? "https://x.com/{$user}/status/{$id}",
            'likes' => $data['likes'] ?? 0,
            'retweets' => $data['retweets'] ?? 0,
            'created_at' => $data['date'] ?? null,
            'videos' => $videos,
            'has_media' => !empty($videos),
            'has_video' => !empty($videos),
            'has_photos' => false
        ];
    }

    /**
     * Resolves direct media URL via redirect headers from d.fixupx.com.
     */
    private function fetchFromDirectRedirect(string $id, string $user): ?array {
        $url = "https://d.fixupx.com/i/status/{$id}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true
        ]);

        curl_exec($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        if ($redirectUrl && (str_contains($redirectUrl, 'video.twimg.com') || preg_match('/\.mp4(\?.*)?$/i', $redirectUrl))) {
            return [
                'id' => $id,
                'author_name' => $user,
                'author_handle' => $user,
                'text' => '',
                'url' => "https://x.com/{$user}/status/{$id}",
                'likes' => 0,
                'retweets' => 0,
                'created_at' => null,
                'videos' => [
                    [
                        'url' => $redirectUrl,
                        'type' => 'video',
                        'thumbnail' => null,
                        'width' => null,
                        'height' => null,
                        'duration' => null
                    ]
                ],
                'has_media' => true,
                'has_video' => true,
                'has_photos' => false
            ];
        }

        return null;
    }

    /**
     * Selects the highest bitrate MP4 variant from a list.
     */
    private function pickBestVariant(array $variants): ?string {
        $bestUrl = null;
        $bestBitrate = -1;

        foreach ($variants as $variant) {
            $contentType = $variant['content_type'] ?? $variant['format'] ?? '';
            $url = $variant['url'] ?? '';

            if (str_contains($contentType, 'video/mp4') || preg_match('/\.mp4(\?.*)?$/i', $url)) {
                $bitrate = $variant['bitrate'] ?? 0;
                if ($bitrate > $bestBitrate) {
                    $bestBitrate = $bitrate;
                    $bestUrl = $url;
                }
            }
        }

        return $bestUrl ?? ($variants[0]['url'] ?? null);
    }

    /**
     * Resolves any redirects for shortened URLs (like t.co).
     */
    private function resolveRedirectUrl(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOBODY => true
        ]);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        return $finalUrl ?: null;
    }

    /**
     * Downloads a video file to a temporary local file.
     * Enforces a maximum file size (in MB).
     *
     * @return string|null Absolute path to temp file, or null if failed / exceeds max size
     */
    public function downloadVideoToTemp(string $url, string $tempDir, int $maxMb = 50): ?string {
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $tmpFile = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . 'tw_' . uniqid('', true) . '.mp4';
        $fp = fopen($tmpFile, 'wb');
        if (!$fp) {
            return null;
        }

        $maxBytes = $maxMb * 1024 * 1024;
        $bytesDownloaded = 0;
        $exceeded = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) use ($maxBytes, &$exceeded, &$bytesDownloaded) {
                $bytesDownloaded = $downloaded;
                if ($maxBytes > 0 && $downloaded > $maxBytes) {
                    $exceeded = true;
                    return 1; // Aborts cURL transfer
                }
                return 0;
            }
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);

        if ($exceeded || !$success || $httpCode !== 200 || filesize($tmpFile) === 0) {
            @unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }
}
