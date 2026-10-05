<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

final class PortalPreview
{
    public static function fromHtml(string $html, string $pageUrl): array
    {
        $values = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $attributes = [];
                if (preg_match_all('/([\w:-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) $attributes[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $key = strtolower($attributes['property'] ?? $attributes['name'] ?? '');
                if ($key !== '' && isset($attributes['content']) && !isset($values[$key])) $values[$key] = trim($attributes['content']);
            }
        }
        $description = trim($values['og:description'] ?? $values['description'] ?? '');
        $image = self::resolveUrl($pageUrl, $values['og:image'] ?? $values['twitter:image'] ?? '');
        return [
            'description' => mb_substr(strip_tags($description), 0, 240, 'UTF-8'),
            'image_url' => $image,
        ];
    }

    public static function resolveUrl(string $base, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) return null;
        $baseParts = parse_url($base);
        if (!$baseParts || !isset($baseParts['scheme'], $baseParts['host'])) return null;
        if (str_starts_with($value, '//')) $value = $baseParts['scheme'] . ':' . $value;
        elseif (!preg_match('~^https?://~i', $value)) {
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) return null;
            $origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
            $basePath = $baseParts['path'] ?? '/';
            $directory = substr($basePath, 0, strrpos($basePath, '/') + 1);
            $path = str_starts_with($value, '/') ? $value : $directory . $value;
            $value = $origin . $path;
        }
        $parts = parse_url($value);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        return $value;
    }

    public static function fetch(string $url): array
    {
        $fallback = ['description' => '', 'image_url' => null];
        if (!extension_loaded('curl')) return $fallback;
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return $fallback;
        $host = $parts['host'];
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true) || !preg_match('/\A[a-z0-9.-]+\z/i', $host)) return $fallback;
        $ips = gethostbynamel($host) ?: [];
        $ips = array_values(array_filter($ips, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false));
        if (!$ips) return $fallback;
        $html = '';
        $curl = curl_init($url);
        if ($curl === false) return $fallback;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["$host:$port:{$ips[0]}"],
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'User-Agent: CommunityMapMakerPortal/1.0'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$html): int {
                if (strlen($html) + strlen($chunk) > 131072) return 0;
                $html .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        if ($ok === false || $status !== 200 || !str_contains(strtolower($contentType), 'text/html')) return $fallback;
        return self::fromHtml($html, $url);
    }
}
