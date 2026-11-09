<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use Closure;
use RuntimeException;

require_once __DIR__ . '/ThumbnailSource.php';

final class ThumbnailBusyException extends RuntimeException {}

final class ThumbnailService
{
    private const MAX_CONCURRENT_GENERATIONS = 2;
    private string $cacheDir;
    private int $ttl;
    private int $maxEntries;

    public function __construct(array $config = [], private ?Closure $download = null)
    {
        $this->cacheDir = (string)($config['cache_dir'] ?? sys_get_temp_dir() . '/cmm-thumbnails-v1');
        $this->ttl = max(60, min(604800, (int)($config['cache_ttl'] ?? 86400)));
        $this->maxEntries = max(1, min(10000, (int)($config['max_entries'] ?? 1000)));
    }

    /** Cache is private; serve only after checking that the Activity still exists. */
    public function get(string $url, ?callable $beforeDownload = null): array
    {
        if (!extension_loaded('gd')) throw new RuntimeException('GD is required for thumbnails.');
        // Use the same URL for validation, cache lookup and HTTP transfer.
        $url = ThumbnailSource::normalizeUrl($url);
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException('Thumbnail cache is not writable.');
        }
        $key = hash('sha256', '400:80:' . (function_exists('imagewebp') ? 'webp:' : 'fallback:') . $url);
        $file = $this->cacheDir . '/' . $key . '.img';
        if (($cached = $this->cached($file)) !== null) return $cached;
        $slot = $this->withCacheLock(fn() => $this->acquireGenerationSlot($key));
        try {
            if (($cached = $this->cached($file)) !== null) return $cached;
            $failed = $this->cacheDir . '/' . $key . '.fail';
            if (is_file($failed) && time() - (int)filemtime($failed) < 60) {
                throw new ThumbnailSourceException('Image is temporarily unavailable.');
            }
            if ($beforeDownload !== null) $beforeDownload();
            try {
                $source = $this->download === null ? ThumbnailSource::fetch($url) : ($this->download)($url);
                $result = $this->render($source);
            } catch (ThumbnailSourceException $error) {
                $this->withCacheLock(function () use ($failed): void {
                    $this->prune();
                    @touch($failed);
                });
                throw $error;
            }
            $temp = tempnam($this->cacheDir, 'thumb-');
            if ($temp === false) throw new RuntimeException('Cannot create thumbnail cache file.');
            try {
                if (file_put_contents($temp, $result['body']) !== strlen($result['body'])) throw new RuntimeException('Cannot save thumbnail cache.');
                $this->withCacheLock(function () use ($temp, $file, $failed): void {
                    $this->prune();
                    if (!rename($temp, $file)) throw new RuntimeException('Cannot save thumbnail cache.');
                    if (is_file($failed)) unlink($failed);
                });
            } finally { if (is_file($temp)) unlink($temp); }
            return $result + ['cached' => false];
        } finally { fclose($slot); }
    }

    /** Only slot registration and cache publication hold this short metadata lock. */
    private function withCacheLock(callable $operation): mixed
    {
        $lock = fopen($this->cacheDir . '/generation.lock', 'c');
        if ($lock === false) throw new RuntimeException('Cannot open thumbnail cache lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock thumbnail cache.');
            return $operation();
        } finally { fclose($lock); }
    }

    /** Register an image key atomically, keeping only two persistent lock files. */
    private function acquireGenerationSlot(string $key)
    {
        $available = null;
        try {
            for ($i = 0; $i < self::MAX_CONCURRENT_GENERATIONS; $i++) {
                $slot = fopen($this->cacheDir . '/generation-' . $i . '.lock', 'c+');
                if ($slot === false) throw new RuntimeException('Cannot open thumbnail generation slot.');
                if (flock($slot, LOCK_EX | LOCK_NB)) {
                    if ($available === null) $available = $slot;
                    else fclose($slot);
                } else {
                    $activeKey = stream_get_contents($slot);
                    fclose($slot);
                    if ($activeKey === $key) throw new ThumbnailBusyException('This image is already being generated.');
                }
            }
            if ($available === null) throw new ThumbnailBusyException('Both thumbnail generation slots are busy.');
            if (!ftruncate($available, 0) || fwrite($available, $key) !== strlen($key) || !fflush($available)) {
                throw new RuntimeException('Cannot register thumbnail generation slot.');
            }
            $result = $available;
            $available = null;
            return $result;
        } finally { if ($available !== null) fclose($available); }
    }

    private function cached(string $file): ?array
    {
        clearstatcache(true, $file);
        if (!is_file($file) || time() - (int)@filemtime($file) >= $this->ttl) return null;
        $body = @file_get_contents($file);
        $info = $body === false ? false : @getimagesizefromstring($body);
        if (!$info) return null;
        return ['body' => $body, 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1], 'cached' => true];
    }

    private function prune(): void
    {
        $files = array_merge(glob($this->cacheDir . '/*.img') ?: [], glob($this->cacheDir . '/*.fail') ?: []);
        usort($files, static fn(string $a, string $b): int => (int)filemtime($a) <=> (int)filemtime($b));
        $remaining = count($files);
        foreach ($files as $file) {
            $ttl = str_ends_with($file, '.fail') ? 60 : $this->ttl;
            if ($remaining < $this->maxEntries && time() - (int)filemtime($file) < $ttl) continue;
            if (@unlink($file)) $remaining--;
        }
    }

    public function render(string $source): array
    {
        if (strlen($source) > ThumbnailSource::MAX_BYTES) throw new ThumbnailSourceException('Image is too large.');
        $info = @getimagesizefromstring($source);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new ThumbnailSourceException('Expected a JPEG, PNG, GIF or WebP image.');
        }
        $pixels = $info[0] * $info[1];
        if ($info[0] < 1 || $info[1] < 1 || $pixels > 6000000) throw new ThumbnailSourceException('Image exceeds six million pixels.');
        $limit = trim((string)ini_get('memory_limit'));
        $bytes = (int)$limit;
        $bytes *= match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        if ($bytes > 0 && memory_get_usage(true) + strlen($source) + $pixels * 8 + 16777216 > $bytes) {
            throw new ThumbnailSourceException('Image exceeds the available memory budget.');
        }
        $image = @imagecreatefromstring($source);
        if ($image === false) throw new ThumbnailSourceException('Image could not be decoded.');
        $thumb = null;
        try {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $stream = fopen('php://temp', 'w+b');
                try {
                    fwrite($stream, $source); rewind($stream);
                    $exif = @exif_read_data($stream);
                } finally { fclose($stream); }
                $orientation = (int)($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
                $angle = match ($orientation) { 3, 4 => 180, 6, 7 => -90, 5, 8 => 90, default => 0 };
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated === false) throw new ThumbnailSourceException('Image orientation failed.');
                    imagedestroy($image); $image = $rotated;
                }
            }
            $width = imagesx($image); $height = imagesy($image);
            $ratio = min(1, 400 / max($width, $height));
            $width = max(1, (int)round($width * $ratio)); $height = max(1, (int)round($height * $ratio));
            $thumb = imagecreatetruecolor($width, $height);
            imagealphablending($thumb, false); imagesavealpha($thumb, true);
            imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            if (!imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image))) {
                throw new ThumbnailSourceException('Image resize failed.');
            }
            ob_start();
            try {
                if (function_exists('imagewebp')) { $ok = imagewebp($thumb, null, 80); $mime = 'image/webp'; }
                elseif ($info[2] === IMAGETYPE_JPEG) { $ok = imagejpeg($thumb, null, 80); $mime = 'image/jpeg'; }
                else { $ok = imagepng($thumb, null, 9); $mime = 'image/png'; }
                $body = (string)ob_get_contents();
            } finally { ob_end_clean(); }
            if (!$ok || $body === '') throw new ThumbnailSourceException('Image encoding failed.');
            return ['body' => $body, 'mime' => $mime, 'width' => $width, 'height' => $height];
        } finally {
            imagedestroy($image);
            if ($thumb !== null) imagedestroy($thumb);
        }
    }
}
