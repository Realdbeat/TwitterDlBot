<?php
namespace TwitterDlBot;

/**
 * Facebook Video & Reel Resolver & Downloader
 * Supports public videos, Facebook Reels, Watch clips, and mobile links in HD / SD.
 */
class FacebookDownloader {
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1';
    
    private int $httpTimeout;

    public function __construct(int $httpTimeout = 30) {
        $this->httpTimeout = $httpTimeout;
    }

    /**
     * Extracts Facebook video & reel links from text.
     * Supports:
     * - https://www.facebook.com/watch/?v=1234567890
     * - https://www.facebook.com/watch?v=1234567890
     * - https://www.facebook.com/reel/1234567890
     * - https://www.facebook.com/username/videos/1234567890/
     * - https://www.facebook.com/video.php?v=1234567890
     * - https://fb.watch/xxxxxx/
     * - https://www.facebook.com/share/r/xxxxxx/
     * - https://www.facebook.com/share/v/xxxxxx/
     * - https://www.facebook.com/share/p/xxxxxx/
     * - https://www.facebook.com/share/xxxxxx/
     * - Mobile links (m.facebook.com, mbasic.facebook.com, web.facebook.com)
     *
     * @return array<int, array{id: string, user: string, url: string, canonical_url: string, type: string}>
     */
    public function extractFacebookLinks(string $text): array {
        $results = [];
        $seen = [];

        // 1. Resolve and extract shortened or share links (fb.watch, facebook.com/share/...)
        $shortPattern = '/https?:\/\/(?:fb\.watch|fb\.gg\/(?:v\/)?|(?:www\.|m\.|web\.)?facebook\.com\/share(?:\/[rvp])?)\/[a-zA-Z0-9_.-]+/i';
        if (preg_match_all($shortPattern, $text, $shortMatches)) {
            foreach (array_unique($shortMatches[0]) as $shortUrl) {
                $expanded = $this->resolveRedirectUrl($shortUrl);
                $targetUrl = ($expanded && $expanded !== $shortUrl) ? $expanded : $shortUrl;

                $id = '';
                $user = 'Facebook';
                $type = 'video';

                if (preg_match('/facebook\.com\/reel\/([0-9]+)/i', $targetUrl, $m)) {
                    $id = $m[1];
                    $type = 'reel';
                } elseif (preg_match('/[?&]v=([0-9]+)/i', $targetUrl, $m)) {
                    $id = $m[1];
                } elseif (preg_match('/facebook\.com\/([a-zA-Z0-9_.-]+)\/videos\/(?:[a-zA-Z0-9_.-]+\/)?([0-9]+)/i', $targetUrl, $m)) {
                    $user = $m[1];
                    $id = $m[2];
                } else {
                    $id = 'fb_' . substr(md5($shortUrl), 0, 12);
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

        // 2. Facebook Reels: /reel/1234567890
        $reelPattern = '/https?:\/\/(?:www\.|m\.|web\.)?facebook\.com\/reel\/([0-9]+)(?:\S*)?/i';
        if (preg_match_all($reelPattern, $text, $reelMatches, PREG_SET_ORDER)) {
            foreach ($reelMatches as $m) {
                $id = $m[1];
                $url = $m[0];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => 'Facebook',
                        'url' => $url,
                        'canonical_url' => "https://www.facebook.com/reel/{$id}",
                        'type' => 'reel'
                    ];
                }
            }
        }

        // 3. Facebook Watch / Videos with ?v=1234567890
        $watchPattern = '/https?:\/\/(?:www\.|m\.|web\.)?facebook\.com\/(?:watch\/?|video\.php)\?(?:[^\s]*&)*v=([0-9]+)(?:\S*)?/i';
        if (preg_match_all($watchPattern, $text, $watchMatches, PREG_SET_ORDER)) {
            foreach ($watchMatches as $m) {
                $id = $m[1];
                $url = $m[0];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => 'Facebook',
                        'url' => $url,
                        'canonical_url' => "https://www.facebook.com/watch/?v={$id}",
                        'type' => 'video'
                    ];
                }
            }
        }

        // 4. Page / User videos: /username/videos/[vb.123/]?1234567890
        $userVideoPattern = '/https?:\/\/(?:www\.|m\.|web\.)?facebook\.com\/([a-zA-Z0-9_.-]+)\/videos\/(?:[a-zA-Z0-9_.-]+\/)?([0-9]+)(?:\S*)?/i';
        if (preg_match_all($userVideoPattern, $text, $userMatches, PREG_SET_ORDER)) {
            foreach ($userMatches as $m) {
                $user = $m[1];
                $id = $m[2];
                $url = $m[0];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => $user,
                        'url' => $url,
                        'canonical_url' => "https://www.facebook.com/{$user}/videos/{$id}",
                        'type' => 'video'
                    ];
                }
            }
        }

        // 5. Facebook post links with story_fbid or posts/123456
        $postPattern = '/https?:\/\/(?:www\.|m\.|web\.)?facebook\.com\/([a-zA-Z0-9_.-]+)\/posts\/([0-9]+)(?:\S*)?/i';
        if (preg_match_all($postPattern, $text, $postMatches, PREG_SET_ORDER)) {
            foreach ($postMatches as $m) {
                $user = $m[1];
                $id = $m[2];
                $url = $m[0];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $results[] = [
                        'id' => $id,
                        'user' => $user,
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
     * Resolves metadata and direct video URLs for a Facebook post/reel.
     */
    public function getVideoInfo(string $url, ?string $id = null, string $user = 'Facebook'): ?array {
        // Method 1: Direct desktop scrape
        $info = $this->fetchDirectScrape($url, $id, $user);
        if ($info !== null && !empty($info['videos'])) {
            return $info;
        }

        // Method 2: Mobile HTML scrape
        $info = $this->fetchMobileScrape($url, $id, $user);
        if ($info !== null && !empty($info['videos'])) {
            return $info;
        }

        // Method 3: SnapSave fallback resolver
        $info = $this->fetchFromSnapSave($url, $id, $user);
        if ($info !== null && !empty($info['videos'])) {
            return $info;
        }

        return null;
    }

    /**
     * Scrapes video streams directly from Facebook using desktop browser headers.
     */
    private function fetchDirectScrape(string $url, ?string $id, string $user): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Sec-Fetch-Mode: navigate'
            ],
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

        if ($httpCode !== 200 || empty($html)) {
            return null;
        }

        return $this->parseFacebookHtml($html, $effectiveUrl, $id, $user);
    }

    /**
     * Scrapes video streams directly from Facebook using mobile browser headers.
     */
    private function fetchMobileScrape(string $url, ?string $id, string $user): ?array {
        $mobileUrl = str_replace(['www.facebook.com', 'web.facebook.com'], 'm.facebook.com', $url);
        if (!str_contains($mobileUrl, 'm.facebook.com')) {
            $mobileUrl = preg_replace('/https?:\/\/[^\/]+/', 'https://m.facebook.com', $mobileUrl);
        }

        $ch = curl_init($mobileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::MOBILE_USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

        if ($httpCode !== 200 || empty($html)) {
            return null;
        }

        return $this->parseFacebookHtml($html, $effectiveUrl, $id, $user);
    }

    /**
     * Parses Facebook HTML body to extract videos, title, thumbnail, author, and duration.
     */
    private function parseFacebookHtml(string $html, string $canonicalUrl, ?string $id, string $user): ?array {
        $videos = [];
        $hdUrl = null;
        $sdUrl = null;

        // 1. Check for HD video stream
        if (preg_match('/browser_native_hd_url["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $hdUrl = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/"playable_url_quality_hd"["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $hdUrl = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/hd_src_no_ratelimit["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $hdUrl = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/"hd_src"["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $hdUrl = $this->cleanEscapedUrl($m[1]);
        }

        // 2. Check for SD video stream
        if (preg_match('/browser_native_sd_url["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $sdUrl = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/"playable_url"["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $sdUrl = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/sd_src_no_ratelimit["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $sdUrl = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/"sd_src"["\s]*:["\s]*"([^"]+)"/', $html, $m)) {
            $sdUrl = $this->cleanEscapedUrl($m[1]);
        }

        // 3. Fallback to OpenGraph video meta tag
        if (!$hdUrl && !$sdUrl) {
            if (preg_match('/<meta\s+(?:property|name)=["\']og:video(?::secure_url)?["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $ogUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (filter_var($ogUrl, FILTER_VALIDATE_URL)) {
                    $sdUrl = $ogUrl;
                }
            }
        }

        if (!$hdUrl && !$sdUrl) {
            return null;
        }

        // Extract metadata: title, description, author, thumbnail, duration
        $title = '';
        if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Clean common Facebook title suffixes
            $title = preg_replace('/\s*\|\s*Facebook$/i', '', $title);
        }

        $text = '';
        if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $text = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (empty($text) && !empty($title)) {
            $text = $title;
        }

        // Author name
        $authorName = ($user !== 'Facebook' && !empty($user)) ? $user : 'Facebook Creator';
        if (preg_match('/"owner"\s*:\s*\{"__typename"\s*:\s*"User"[^}]*"name"\s*:\s*"([^"]+)"/', $html, $m)) {
            $authorName = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/"actors"\s*:\s*\[\{"__typename"[^}]*"name"\s*:\s*"([^"]+)"/', $html, $m)) {
            $authorName = $this->cleanEscapedUrl($m[1]);
        }

        // Thumbnail
        $thumbnail = null;
        if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $thumbnail = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/"preferred_thumbnail"\s*:\s*\{"image"\s*:\s*\{"uri"\s*:\s*"([^"]+)"\}/', $html, $m)) {
            $thumbnail = $this->cleanEscapedUrl($m[1]);
        } elseif (preg_match('/"(?:image|thumbnail|previewImage|snapshot)\w*"\s*:\s*\{"uri"\s*:\s*"([^"]+)"\}/i', $html, $m)) {
            $thumbnail = $this->cleanEscapedUrl($m[1]);
        }

        // Duration
        $duration = null;
        if (preg_match('/"playable_duration_in_ms"\s*:\s*([0-9]+)/', $html, $m)) {
            $duration = (int)round(((int)$m[1]) / 1000);
        } elseif (preg_match('/"duration_s"\s*:\s*([0-9]+)/', $html, $m)) {
            $duration = (int)$m[1];
        }

        // Video ID
        $videoId = $id;
        if (!$videoId || str_starts_with($videoId, 'fb_')) {
            if (preg_match('/"video_id"\s*:\s*"([0-9]+)"/', $html, $m)) {
                $videoId = $m[1];
            } elseif (preg_match('/[?&]v=([0-9]+)/', $canonicalUrl, $m)) {
                $videoId = $m[1];
            } elseif (preg_match('/facebook\.com\/reel\/([0-9]+)/', $canonicalUrl, $m)) {
                $videoId = $m[1];
            }
        }

        if ($hdUrl) {
            $videos[] = [
                'url' => $hdUrl,
                'quality' => 'hd',
                'type' => 'video',
                'thumbnail' => $thumbnail,
                'width' => 1280,
                'height' => 720,
                'duration' => $duration
            ];
        }

        if ($sdUrl && $sdUrl !== $hdUrl) {
            $videos[] = [
                'url' => $sdUrl,
                'quality' => 'sd',
                'type' => 'video',
                'thumbnail' => $thumbnail,
                'width' => 640,
                'height' => 360,
                'duration' => $duration
            ];
        }

        return [
            'id' => $videoId ?: 'fb_video',
            'author_name' => $authorName,
            'author_handle' => ($user !== 'Facebook') ? $user : 'facebook',
            'text' => $text,
            'url' => $canonicalUrl,
            'videos' => $videos,
            'has_video' => true,
            'has_media' => true,
            'created_at' => time()
        ];
    }

    /**
     * Resolves Facebook video via SnapSave fallback service.
     */
    private function fetchFromSnapSave(string $url, ?string $id, string $user): ?array {
        $ch = curl_init('https://snapsave.app/action.php?lang=en');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(['url' => $url]),
            CURLOPT_HTTPHEADER => [
                'Origin: https://snapsave.app',
                'Referer: https://snapsave.app/',
                'User-Agent: ' . self::USER_AGENT,
                'X-Requested-With: XMLHttpRequest'
            ],
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        // Unpack the Hunter-obfuscated JS string
        $unpacked = $this->unpackHunterJs($response);
        if (!$unpacked) {
            return null;
        }

        // Extract video download links from unpacked HTML
        $videos = [];
        $thumbnail = null;
        $title = '';

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $unpacked, $m)) {
            $thumbnail = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<p[^>]*class=["\'][^"\']*video-des[^"\']*["\'][^>]*>(.*?)<\/p>/is', $unpacked, $m)) {
            $title = trim(strip_tags($m[1]));
        }

        // Find download links
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $unpacked, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[2]));

                if (str_contains($href, 'token=') || str_contains($href, 'fbcdn.net') || str_contains($href, 'rapidcdn.app')) {
                    $quality = (stripos($label, 'HD') !== false || stripos($href, 'hd') !== false) ? 'hd' : 'sd';

                    // If token on rapidcdn, extract direct FB CDN URL if present
                    $directUrl = $href;
                    if (str_contains($href, 'token=')) {
                        parse_str((string)parse_url($href, PHP_URL_QUERY), $query);
                        if (!empty($query['token'])) {
                            $parts = explode('.', $query['token']);
                            if (isset($parts[1])) {
                                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                                if (!empty($payload['url'])) {
                                    $directUrl = $payload['url'];
                                }
                            }
                        }
                    }

                    $videos[] = [
                        'url' => $directUrl,
                        'quality' => $quality,
                        'type' => 'video',
                        'thumbnail' => $thumbnail,
                        'width' => ($quality === 'hd') ? 1280 : 640,
                        'height' => ($quality === 'hd') ? 720 : 360,
                        'duration' => null
                    ];
                }
            }
        }

        if (empty($videos)) {
            return null;
        }

        // Sort videos so HD comes first
        usort($videos, function ($a, $b) {
            return ($a['quality'] === 'hd') ? -1 : 1;
        });

        return [
            'id' => $id ?: 'fb_video',
            'author_name' => ($user !== 'Facebook' && !empty($user)) ? $user : 'Facebook Creator',
            'author_handle' => ($user !== 'Facebook') ? $user : 'facebook',
            'text' => $title,
            'url' => $url,
            'videos' => $videos,
            'has_video' => true,
            'has_media' => true,
            'created_at' => time()
        ];
    }

    /**
     * Unpacks Hunter-packed JavaScript string (eval(function(h,u,n,t,e,r){...}(...))).
     */
    private function unpackHunterJs(string $html): ?string {
        if (!preg_match('/eval\(function\(h,u,n,t,e,r\).+?\}\((.+?)\)\)/s', $html, $m)) {
            return null;
        }

        $argsStr = $m[1];
        if (!preg_match('/"([^"]+)",\s*(\d+),\s*"([^"]+)",\s*(\d+),\s*(\d+),\s*(\d+)/', $argsStr, $argMatch)) {
            return null;
        }

        $h = $argMatch[1];
        $u = (int)$argMatch[2];
        $n = $argMatch[3];
        $t = (int)$argMatch[4];
        $e = (int)$argMatch[5];
        $r = (int)$argMatch[6];

        $res = '';
        $len = strlen($h);
        if (!isset($n[$e])) {
            return null;
        }
        $delimiter = $n[$e];
        $i = 0;

        $nLen = strlen($n);
        $search = [];
        $replace = [];
        for ($j = 0; $j < $nLen; $j++) {
            $search[] = $n[$j];
            $replace[] = (string)$j;
        }

        while ($i < $len) {
            $s = '';
            while ($i < $len && $h[$i] !== $delimiter) {
                $s .= $h[$i];
                $i++;
            }
            $i++;

            if ($s !== '') {
                $s = str_replace($search, $replace, $s);
                $val = base_convert($s, $e, 10);
                $charVal = (int)$val - $t;
                if ($charVal >= 0) {
                    $res .= chr($charVal);
                }
            }
        }

        return rawurldecode($res);
    }

    /**
     * Follows redirect headers to discover the canonical expanded URL.
     */
    public function resolveRedirectUrl(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        return (!empty($finalUrl) && filter_var($finalUrl, FILTER_VALIDATE_URL)) ? $finalUrl : null;
    }

    /**
     * Downloads a remote Facebook video to a local temp file.
     * Enforces size limit to stay within Telegram Bot API upload threshold.
     */
    public function downloadVideoToTemp(string $url, string $tempDir, int $maxMb = 50): ?string {
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $tmpFile = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . 'fb_' . uniqid('', true) . '.mp4';
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
            CURLOPT_SSL_VERIFYPEER => false,
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

    /**
     * Decodes JSON/JavaScript string escaping and HTML entities in URLs.
     */
    private function cleanEscapedUrl(string $url): string {
        $decoded = json_decode('"' . $url . '"');
        if (is_string($decoded)) {
            $url = $decoded;
        } else {
            $url = stripcslashes($url);
            $url = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
                return mb_chr(hexdec($m[1]), 'UTF-8');
            }, $url);
        }
        return html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

