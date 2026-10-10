<?php
namespace TwitterDlBot;

/**
 * TikTok Video & Media Resolver & Downloader
 * Supports watermark-free HD videos, audio/sound extraction, and photo slideshows.
 */
class TikTokDownloader {
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private int $httpTimeout;

    public function __construct(int $httpTimeout = 30) {
        $this->httpTimeout = $httpTimeout;
    }

    /**
     * Extracts TikTok links, video IDs, usernames, and media types from text.
     * Supports:
     * - https://www.tiktok.com/@username/video/1234567890
     * - https://www.tiktok.com/@username/photo/1234567890
     * - https://m.tiktok.com/v/1234567890.html
     * - https://vm.tiktok.com/xxxxxx/ or https://vt.tiktok.com/xxxxxx/
     * - https://www.tiktok.com/t/xxxxxx/
     * - https://tnktok.com/...
     *
     * @return array<int, array{id: string, user: string, url: string, type: string}>
     */
    public function extractTikTokLinks(string $text): array {
        $results = [];
        $seen = [];

        // 1. Resolve and extract shortened TikTok URLs (vm.tiktok.com, vt.tiktok.com, tiktok.com/t/)
        $shortPattern = '/https?:\/\/(?:(?:vm|vt)\.tiktok\.com|(?:www\.)?tiktok\.com\/t)\/[a-zA-Z0-9_-]+/i';
        if (preg_match_all($shortPattern, $text, $shortMatches)) {
            foreach (array_unique($shortMatches[0]) as $shortUrl) {
                $expanded = $this->resolveRedirectUrl($shortUrl);
                $targetUrl = ($expanded && $expanded !== $shortUrl) ? $expanded : $shortUrl;

                // Extract details from expanded URL if possible
                $id = '';
                $user = 'TikTok';
                $type = 'video';

                if (preg_match('/tiktok\.com\/@([a-zA-Z0-9_.-]+)\/(video|photo)\/([0-9]+)/i', $targetUrl, $m)) {
                    $user = $m[1];
                    $type = $m[2];
                    $id = $m[3];
                } elseif (preg_match('/\/v\/([0-9]+)/i', $targetUrl, $m)) {
                    $id = $m[1];
                } else {
                    // Use unique hash of the short URL if ID couldn't be extracted
                    $id = 'short_' . substr(md5($shortUrl), 0, 12);
                }

                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => $user,
                        'url' => $shortUrl,
                        'canonical_url' => $targetUrl,
                        'type' => $type
                    ];
                }
            }
        }

        // 2. Extract standard and photo TikTok links
        $fullPattern = '/https?:\/\/(?:(?:www|m)\.)?(?:tiktok\.com|tnktok\.com)\/@([a-zA-Z0-9_.-]+)\/(video|photo)\/([0-9]+)(?:\S*)?/i';
        if (preg_match_all($fullPattern, $text, $fullMatches, PREG_SET_ORDER)) {
            foreach ($fullMatches as $m) {
                $user = $m[1];
                $type = $m[2];
                $id = $m[3];
                $url = $m[0];

                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => $user,
                        'url' => $url,
                        'canonical_url' => $url,
                        'type' => $type
                    ];
                }
            }
        }

        // 3. Extract mobile web /v/ links (m.tiktok.com/v/1234567890.html)
        $mobilePattern = '/https?:\/\/m\.tiktok\.com\/v\/([0-9]+)(?:\.html)?(?:\S*)?/i';
        if (preg_match_all($mobilePattern, $text, $mobileMatches, PREG_SET_ORDER)) {
            foreach ($mobileMatches as $m) {
                $id = $m[1];
                $url = $m[0];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => 'TikTok',
                        'url' => $url,
                        'canonical_url' => $url,
                        'type' => 'video'
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Extracts a TikTok profile username from message text if a profile URL is provided.
     * Excludes direct video/photo URLs.
     */
    public function extractProfileUsername(string $text): ?string {
        // Exclude if it's explicitly a video or photo URL
        if (preg_match('/tiktok\.com\/@[a-zA-Z0-9_.-]+\/(video|photo)\/[0-9]+/i', $text)) {
            return null;
        }

        if (preg_match('/(?:https?:\/\/)?(?:(?:www|m)\.)?tiktok\.com\/@([a-zA-Z0-9_.-]+)(?:\/)?(?:\?[^\s]*)?/i', $text, $m)) {
            return ltrim($m[1], '@');
        }

        if (preg_match('/(?:https?:\/\/)?(?:(?:www|m)\.)?urlebird\.com\/user\/([a-zA-Z0-9_.-]+)(?:\/)?(?:\?[^\s]*)?/i', $text, $m)) {
            return ltrim($m[1], '@');
        }

        return null;
    }

    /**
     * Retrieves creator profile metadata (nickname, video count, follower count, avatar).
     */
    public function getUserProfileInfo(string $username): ?array {
        $username = ltrim($username, '@');
        $url = "https://www.tiktok.com/@{$username}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 200 && $html && preg_match('/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)<\/script>/s', $html, $m)) {
            $json = json_decode($m[1], true);
            $userDetail = $json['__DEFAULT_SCOPE__']['webapp.user-detail'] ?? [];
            if (!empty($userDetail['userInfo']['user'])) {
                $user = $userDetail['userInfo']['user'];
                $stats = $userDetail['userInfo']['stats'] ?? [];
                return [
                    'username' => $user['uniqueId'] ?? $username,
                    'nickname' => $user['nickname'] ?? $username,
                    'avatar' => $user['avatarLarger'] ?? $user['avatarThumb'] ?? null,
                    'sec_uid' => $user['secUid'] ?? null,
                    'video_count' => (int)($stats['videoCount'] ?? 0),
                    'follower_count' => (int)($stats['followerCount'] ?? 0),
                    'likes_count' => (float)($stats['heartCount'] ?? $stats['heart'] ?? 0)
                ];
            }
        }

        // Fallback: Urlebird user page parsing
        $ch2 = curl_init("https://urlebird.com/user/{$username}/");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT
        ]);
        $uHtml = curl_exec($ch2);
        if (curl_getinfo($ch2, CURLINFO_HTTP_CODE) === 200 && $uHtml) {
            $nickname = $username;
            if (preg_match('/<title>([^<]+)\(@[^\)]+\)<\/title>/i', $uHtml, $titleM)) {
                $nickname = trim($titleM[1]);
            }
            $videoCount = 0;
            if (preg_match('/([0-9,.]+)\s*(?:videos|Videos)/i', $uHtml, $vcM)) {
                $videoCount = (int)str_replace([',', '.'], '', $vcM[1]);
            }
            return [
                'username' => $username,
                'nickname' => $nickname,
                'avatar' => null,
                'sec_uid' => null,
                'video_count' => $videoCount,
                'follower_count' => 0,
                'likes_count' => 0
            ];
        }

        return null;
    }

    /**
     * Fetches user videos in paginated batches.
     *
     * @return array{videos: array<int, array{id: string, title: string, url: string}>, has_more: bool, pagination_state: ?array}
     */
    public function fetchUserVideos(string $username, ?array $paginationState = null): array {
        $username = ltrim($username, '@');
        $videos = [];
        $nextState = null;
        $hasMore = false;

        if ($paginationState === null) {
            // Page 1: Initial web load
            $ch = curl_init("https://urlebird.com/user/{$username}/");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->httpTimeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => self::USER_AGENT
            ]);
            $html = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code === 200 && $html) {
                preg_match_all('/https:\/\/urlebird\.com\/video\/([a-zA-Z0-9_-]+)-([0-9]{15,25})\//i', $html, $matches, PREG_SET_ORDER);
                $seen = [];
                foreach ($matches as $m) {
                    $vid = $m[2];
                    if (!isset($seen[$vid])) {
                        $seen[$vid] = true;
                        $videos[] = [
                            'id' => $vid,
                            'title' => trim(str_replace('-', ' ', $m[1])),
                            'url' => "https://www.tiktok.com/@{$username}/video/{$vid}"
                        ];
                    }
                }

                // Extract load_more button attributes
                if (preg_match('/<button[^>]*id="load_more"[^>]*>/i', $html, $btnMatch)) {
                    $btn = $btnMatch[0];
                    preg_match('/data-user-id="([^"]*)"/i', $btn, $uidM);
                    preg_match('/data-sec-uid="([^"]*)"/i', $btn, $secM);
                    preg_match('/data-cursor="([^"]*)"/i', $btn, $curM);
                    preg_match('/data-x="([^"]*)"/i', $btn, $xM);
                    preg_match('/data-page="([^"]*)"/i', $btn, $pageM);

                    if (!empty($curM[1])) {
                        $hasMore = true;
                        $nextState = [
                            'user_id' => $uidM[1] ?? '',
                            'sec_uid' => $secM[1] ?? '',
                            'cursor' => $curM[1] ?? '',
                            'lang' => '',
                            'page' => $pageM[1] ?? '2',
                            'x' => $xM[1] ?? ''
                        ];
                    }
                }
            }
        } else {
            // Page 2+: AJAX load more
            $ch = curl_init('https://urlebird.com/ajax/');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'action' => 'user',
                    'data' => json_encode($paginationState)
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->httpTimeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => [
                    'X-Requested-With: XMLHttpRequest',
                    'Referer: https://urlebird.com/user/' . $username . '/'
                ]
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code === 200 && $res) {
                $data = json_decode($res, true);
                if (!empty($data['thumbs'])) {
                    preg_match_all('/https:\/\/urlebird\.com\/video\/([a-zA-Z0-9_-]+)-([0-9]{15,25})\//i', $data['thumbs'], $matches, PREG_SET_ORDER);
                    $seen = [];
                    foreach ($matches as $m) {
                        $vid = $m[2];
                        if (!isset($seen[$vid])) {
                            $seen[$vid] = true;
                            $videos[] = [
                                'id' => $vid,
                                'title' => trim(str_replace('-', ' ', $m[1])),
                                'url' => "https://www.tiktok.com/@{$username}/video/{$vid}"
                            ];
                        }
                    }

                    $hasMore = !empty($data['has_more']);
                    if ($hasMore && !empty($data['cursor'])) {
                        $nextState = [
                            'user_id' => $data['u'] ?? ($paginationState['user_id'] ?? ''),
                            'sec_uid' => $data['s'] ?? ($paginationState['sec_uid'] ?? ''),
                            'cursor' => $data['cursor'] ?? '',
                            'lang' => '',
                            'page' => (string)($data['page'] ?? ((int)($paginationState['page'] ?? 2) + 1)),
                            'x' => $data['x'] ?? ($paginationState['x'] ?? '')
                        ];
                    }
                }
            }
        }

        return [
            'videos' => $videos,
            'has_more' => $hasMore,
            'pagination_state' => $nextState
        ];
    }

    /**
     * Resolves TikTok video metadata, download links (watermark-free), audio, and images.
     */
    public function getVideoInfo(string $url, ?string $id = null, string $user = 'TikTok'): ?array {
        // Method 1: TikWM API (HD watermark-free)
        $info = $this->fetchFromTikWm($url, $id, $user);
        if ($info !== null) {
            return $info;
        }

        // Method 2: tnktok / fxTikTok embed resolver
        $info = $this->fetchFromTnkTok($url, $id, $user);
        if ($info !== null) {
            return $info;
        }

        return null;
    }

    /**
     * Resolves metadata via TikWM API.
     */
    private function fetchFromTikWm(string $url, ?string $id, string $user): ?array {
        $endpoints = [
            'https://www.tikwm.com/api/',
            'https://tikwm.com/api/'
        ];

        foreach ($endpoints as $apiUrl) {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'url' => $url,
                    'hd' => 1
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_TIMEOUT => $this->httpTimeout,
                CURLOPT_CONNECTTIMEOUT => 10,
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
            if (!isset($data['code']) || $data['code'] !== 0 || empty($data['data'])) {
                continue;
            }

            $d = $data['data'];
            $videoId = !empty($d['id']) ? (string)$d['id'] : ($id ?? '');
            $authorName = $d['author']['nickname'] ?? $user;
            $authorHandle = $d['author']['unique_id'] ?? $user;

            $videos = [];

            // 1. HD watermark-free video
            if (!empty($d['hdplay'])) {
                $videos[] = [
                    'url' => $d['hdplay'],
                    'type' => 'video',
                    'quality' => 'HD No Watermark',
                    'width' => null,
                    'height' => null,
                    'duration' => $d['duration'] ?? null,
                    'thumbnail' => $d['cover'] ?? null
                ];
            }

            // 2. Standard watermark-free video
            if (!empty($d['play'])) {
                $videos[] = [
                    'url' => $d['play'],
                    'type' => 'video',
                    'quality' => 'No Watermark',
                    'width' => null,
                    'height' => null,
                    'duration' => $d['duration'] ?? null,
                    'thumbnail' => $d['cover'] ?? null
                ];
            }

            // 3. Watermarked video fallback
            if (empty($videos) && !empty($d['wmplay'])) {
                $videos[] = [
                    'url' => $d['wmplay'],
                    'type' => 'video',
                    'quality' => 'Watermarked',
                    'width' => null,
                    'height' => null,
                    'duration' => $d['duration'] ?? null,
                    'thumbnail' => $d['cover'] ?? null
                ];
            }

            // Music / audio information
            $music = null;
            if (!empty($d['music'])) {
                $music = [
                    'url' => $d['music'],
                    'title' => $d['music_info']['title'] ?? 'Original Sound',
                    'author' => $d['music_info']['author'] ?? $authorName,
                    'duration' => $d['music_info']['duration'] ?? null
                ];
            }

            // Images for photo slideshows
            $images = [];
            if (!empty($d['images']) && is_array($d['images'])) {
                $images = $d['images'];
            }

            return [
                'id' => $videoId,
                'author_name' => $authorName,
                'author_handle' => $authorHandle,
                'text' => $d['title'] ?? '',
                'url' => !empty($videoId) ? "https://www.tiktok.com/@{$authorHandle}/video/{$videoId}" : $url,
                'likes' => $d['digg_count'] ?? 0,
                'shares' => $d['share_count'] ?? 0,
                'comments' => $d['comment_count'] ?? 0,
                'duration' => $d['duration'] ?? null,
                'videos' => $videos,
                'music' => $music,
                'images' => $images,
                'has_video' => !empty($videos),
                'has_images' => !empty($images),
                'source' => 'tikwm'
            ];
        }

        return null;
    }

    /**
     * Resolves metadata via tnktok (fxTikTok) proxy.
     */
    private function fetchFromTnkTok(string $url, ?string $id, string $user): ?array {
        $videoId = $id;

        // If ID is not known, try to extract from resolved URL
        if (!$videoId || str_starts_with($videoId, 'short_')) {
            $expanded = $this->resolveRedirectUrl($url);
            if ($expanded && preg_match('/\/video\/([0-9]+)/i', $expanded, $m)) {
                $videoId = $m[1];
            }
        }

        if (!$videoId || str_starts_with($videoId, 'short_')) {
            return null;
        }

        $tnkUrl = "https://tnktok.com/@{$user}/video/{$videoId}";
        $ch = curl_init($tnkUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'facebookexternalhit/1.1 (TelegramTikTokDlBot/1.0)',
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || !$html) {
            return null;
        }

        // Extract og:video
        if (!preg_match('/<meta\s+property="og:video"\s+content="([^"]+)"/i', $html, $videoMatch)) {
            return null;
        }

        $videoUrl = html_entity_decode($videoMatch[1], ENT_QUOTES, 'UTF-8');

        // Extract og:description (caption)
        $description = '';
        if (preg_match('/<meta\s+property="og:description"\s+content="([^"]*)"/i', $html, $descMatch)) {
            $description = html_entity_decode($descMatch[1], ENT_QUOTES, 'UTF-8');
        }

        // Extract title / author
        $authorName = $user;
        if (preg_match('/<meta\s+property="og:title"\s+content="([^"]*)"/i', $html, $titleMatch)) {
            $authorName = html_entity_decode($titleMatch[1], ENT_QUOTES, 'UTF-8');
        }

        // Extract width & height
        $width = null;
        $height = null;
        if (preg_match('/<meta\s+property="og:video:width"\s+content="([0-9]+)"/i', $html, $wMatch)) {
            $width = (int)$wMatch[1];
        }
        if (preg_match('/<meta\s+property="og:video:height"\s+content="([0-9]+)"/i', $html, $hMatch)) {
            $height = (int)$hMatch[1];
        }

        return [
            'id' => $videoId,
            'author_name' => $authorName,
            'author_handle' => $user,
            'text' => $description,
            'url' => "https://www.tiktok.com/@{$user}/video/{$videoId}",
            'likes' => 0,
            'shares' => 0,
            'comments' => 0,
            'duration' => null,
            'videos' => [
                [
                    'url' => $videoUrl,
                    'type' => 'video',
                    'quality' => 'Standard',
                    'width' => $width,
                    'height' => $height,
                    'duration' => null,
                    'thumbnail' => null
                ]
            ],
            'music' => null,
            'images' => [],
            'has_video' => true,
            'has_images' => false,
            'source' => 'tnktok'
        ];
    }

    /**
     * Resolves redirects for shortened URLs (vm.tiktok.com, vt.tiktok.com, etc.).
     */
    public function resolveRedirectUrl(string $url): ?string {
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
        return $this->downloadFileToTemp($url, $tempDir, 'tt_video_', '.mp4', $maxMb);
    }

    /**
     * Downloads an audio file to a temporary local file.
     *
     * @return string|null Absolute path to temp file, or null if failed / exceeds max size
     */
    public function downloadAudioToTemp(string $url, string $tempDir, int $maxMb = 30): ?string {
        return $this->downloadFileToTemp($url, $tempDir, 'tt_audio_', '.mp3', $maxMb);
    }

    /**
     * Downloads a generic media file with streaming and size enforcement.
     */
    private function downloadFileToTemp(string $url, string $tempDir, string $prefix, string $extension, int $maxMb): ?string {
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $tmpFile = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . $prefix . uniqid('', true) . $extension;
        $fp = fopen($tmpFile, 'wb');
        if (!$fp) {
            return null;
        }

        $maxBytes = $maxMb * 1024 * 1024;
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
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) use ($maxBytes, &$exceeded) {
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
