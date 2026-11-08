<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use RuntimeException;

final class ThumbnailSourceException extends RuntimeException {}

/** Fetch public HTTP images, pinning every connection to a validated IPv4 address. */
final class ThumbnailSource
{
    public const MAX_BYTES = 8388608;

    /** Encode raw UTF-8 bytes without changing URL delimiters or existing escapes. */
    public static function normalizeUrl(string $url): string
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x1f\x7f\\\\]/', $url) || preg_match('//u', $url) !== 1) {
            throw new ThumbnailSourceException('Invalid image URL.');
        }
        $url = preg_replace_callback('/[\x20\x80-\xff]/', static fn(array $match): string => rawurlencode($match[0]), $url);
        $parts = parse_url($url);
        if (strlen($url) > 4096 || !filter_var($url, FILTER_VALIDATE_URL) || !$parts
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ThumbnailSourceException('Expected an HTTP(S) image URL without credentials or fragment.');
        }
        return $url;
    }

    public static function target(string $url, ?callable $resolve = null): array
    {
        $url = self::normalizeUrl($url);
        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true) || !preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            throw new ThumbnailSourceException('Unsupported image host or port.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? [$host] : ($resolve === null ? (gethostbynamel($host) ?: []) : $resolve($host));
        if (!$ips) throw new ThumbnailSourceException('Image host did not resolve.');
        foreach ($ips as $ip) {
            $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (!filter_var($ip, FILTER_VALIDATE_IP, $flags) || (int)explode('.', $ip)[0] >= 224
                || (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))
                || preg_match('/\A(?:100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|192\.0\.0\.)/', $ip)) {
                throw new ThumbnailSourceException('Image host must use public addresses.');
            }
        }
        return [$host, $port, $ips[0]];
    }

    public static function fetch(string $url): string
    {
        if (!extension_loaded('curl')) throw new RuntimeException('cURL is required for thumbnails.');
        require_once __DIR__ . '/PortalPreview.php';
        $deadline = microtime(true) + 12;
        for ($hop = 0; $hop <= 3; $hop++) {
            $url = self::normalizeUrl($url);
            [$host, $port, $ip] = self::target($url);
            $remaining = (int)(($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) throw new ThumbnailSourceException('Image download timed out.');
            $body = ''; $location = null; $headerBytes = 0;
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => ["$host:$port:$ip"],
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining),
                CURLOPT_TIMEOUT_MS => $remaining,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/gif,image/webp', 'User-Agent: CommunityMapMakerThumbnail/1.0'],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$headerBytes): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 16384) return 0;
                    if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            try {
                $ok = curl_exec($curl);
                $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            } finally { curl_close($curl); }
            if ($ok === false) throw new ThumbnailSourceException('Image download failed or exceeded the size limit.');
            if ($status === 200) return $body;
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && $hop < 3) {
                $url = PortalPreview::resolveUrl($url, $location) ?? '';
                continue;
            }
            throw new ThumbnailSourceException('Image server returned an unsupported response.');
        }
        throw new ThumbnailSourceException('Too many redirects.');
    }
}
