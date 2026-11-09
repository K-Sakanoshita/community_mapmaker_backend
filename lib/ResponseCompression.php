<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

/** Opt-in compression for public Activity GET responses, including CSV exports. */
final class ResponseCompression
{
    public static function start(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !function_exists('gzencode') || headers_sent()) return;
        $zlib = strtolower(trim((string)ini_get('zlib.output_compression')));
        if (!in_array($zlib, ['', '0', 'off'], true) || in_array('ob_gzhandler', ob_list_handlers(), true)) return;

        ob_start(static function (string $body): string {
            if (headers_sent() || http_response_code() !== 200) return $body;
            $type = ''; $vary = [];
            foreach (headers_list() as $header) {
                [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
                $name = strtolower(trim($name)); $value = trim($value);
                if ($name === 'content-encoding') return $body;
                if ($name === 'cache-control' && preg_match('/\bno-transform\b/i', $value)) return $body;
                if ($name === 'content-type') $type = strtolower(trim(explode(';', $value)[0]));
                if ($name === 'vary') $vary = array_merge($vary, array_map('trim', explode(',', $value)));
            }
            if (!in_array($type, ['application/json', 'text/csv'], true)) return $body;
            if (!in_array('*', $vary, true) && !in_array('accept-encoding', array_map('strtolower', $vary), true)) {
                $vary[] = 'Accept-Encoding';
                header('Vary: ' . implode(', ', array_unique($vary)));
            }
            if (strlen($body) < 1024 || !self::acceptsGzip((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''))) return $body;
            $compressed = gzencode($body, 4);
            if ($compressed === false || strlen($compressed) >= strlen($body)) return $body;
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($compressed));
            return $compressed;
        });
    }

    public static function acceptsGzip(string $header): bool
    {
        $qualities = [];
        foreach (explode(',', strtolower($header)) as $entry) {
            $parts = array_map('trim', explode(';', $entry));
            $coding = array_shift($parts);
            if (!in_array($coding, ['gzip', '*'], true)) continue;
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/\Aq\s*=\s*(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)\z/', $parameter, $match)) {
                    $quality = min($quality, (float)$match[1]);
                } else { $quality = 0.0; }
            }
            $qualities[$coding] = min($qualities[$coding] ?? 1.0, $quality);
        }
        return ($qualities['gzip'] ?? $qualities['*'] ?? 0.0) > 0;
    }
}
