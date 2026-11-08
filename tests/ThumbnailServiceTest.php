<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ThumbnailBusyException;
use CommunityMapMaker\Activity\ThumbnailService;
use CommunityMapMaker\Activity\ThumbnailSource;
use CommunityMapMaker\Activity\ThumbnailSourceException;

require_once dirname(__DIR__) . '/lib/ThumbnailService.php';
if (!extension_loaded('gd')) throw new RuntimeException('Run this test with GD enabled.');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
$reject = static function (callable $callback, string $message) use ($check): void {
    try { $callback(); } catch (ThumbnailSourceException) { $check(true, $message); return; }
    $check(false, $message);
};
$public = static fn(string $host): array => ['93.184.215.14'];
$japaneseUrl = 'https://example.org/画像/公園.jpg?名前=写真&token=a%2Fb+1';
$encodedUrl = 'https://example.org/%E7%94%BB%E5%83%8F/%E5%85%AC%E5%9C%92.jpg?%E5%90%8D%E5%89%8D=%E5%86%99%E7%9C%9F&token=a%2Fb+1';
$check(ThumbnailSource::normalizeUrl($japaneseUrl) === $encodedUrl, 'Encode Japanese path and query while preserving delimiters and escapes');
$check(ThumbnailSource::normalizeUrl($encodedUrl) === $encodedUrl, 'Do not double-encode escaped URLs');
$check(ThumbnailSource::normalizeUrl('https://example.org/公園 写真.jpg?q=公園 写真') === 'https://example.org/%E5%85%AC%E5%9C%92%20%E5%86%99%E7%9C%9F.jpg?q=%E5%85%AC%E5%9C%92%20%E5%86%99%E7%9C%9F', 'Encode spaces in Japanese filenames and query values');
$reject(static fn() => ThumbnailSource::normalizeUrl('https://bad host/画像.jpg'), 'Do not allow spaces in host names');
$check(ThumbnailSource::normalizeUrl('https://example.org/%E7%94%BB像.jpg?q=%252F') === 'https://example.org/%E7%94%BB%E5%83%8F.jpg?q=%252F', 'Handle mixed raw and escaped Japanese without changing escaped percent');
$check(ThumbnailSource::target($japaneseUrl, $public) === ['example.org', 443, '93.184.215.14'], 'Japanese URL uses the validated host');
$reject(static fn() => ThumbnailSource::target('http://127.0.0.1/画像.jpg'), 'Normalization must not bypass the public-address check');
$check(ThumbnailSource::target('https://example.org/photo.png', $public) === ['example.org', 443, '93.184.215.14'], 'Pin public DNS result');
foreach (['http://127.0.0.1/a', 'http://10.0.0.1/a', 'http://169.254.169.254/a', 'http://100.64.0.1/a',
    'http://192.0.0.1/a', 'http://0.0.0.0/a', 'http://224.0.0.1/a', 'http://[::1]/a',
    'ftp://example.org/a', 'https://user:pass@example.org/a', 'https://example.org/a#x',
    'https://example.org:8080/a', "https://example.org/\r\na", 'http://example.org\\@127.0.0.1/a'] as $url) {
    $reject(static fn() => ThumbnailSource::target($url, $public), 'Reject unsafe target: ' . $url);
}
$reject(static fn() => ThumbnailSource::target('https://example.org/a', static fn() => ['93.184.215.14', '127.0.0.1']), 'Reject mixed public/private DNS');
$reject(static fn() => ThumbnailSource::target('https://example.org/a', static fn() => []), 'Reject unresolved DNS');
// Calling fetch proves unsafe targets are rejected before any network connection.
$reject(static fn() => ThumbnailSource::fetch('http://127.0.0.1/private'), 'Block loopback in the actual fetch path');

$image = imagecreatetruecolor(1000, 500);
for ($x = 0; $x < 1000; $x++) {
    $color = imagecolorallocate($image, $x % 256, ($x * 3) % 256, ($x * 7) % 256);
    imageline($image, $x, 0, $x, 499, $color);
}
ob_start(); imagejpeg($image, null, 100); $jpeg = ob_get_clean(); imagedestroy($image);
$dir = sys_get_temp_dir() . '/cmm-thumb-test-' . bin2hex(random_bytes(6));
$downloads = 0; $budgets = 0;
$service = new ThumbnailService(['cache_dir' => $dir, 'max_entries' => 2], static function () use (&$downloads, $jpeg): string { $downloads++; return $jpeg; });
try {
    $first = $service->get('https://example.org/a.jpg', static function () use (&$budgets): void { $budgets++; });
    $check($first['width'] === 400 && $first['height'] === 200, 'Preserve aspect ratio and cap long edge at 400');
    $check($first['mime'] === (function_exists('imagewebp') ? 'image/webp' : 'image/jpeg') && strlen($first['body']) < strlen($jpeg), 'Encode and reduce JPEG size');
    $check(!$first['cached'] && getimagesizefromstring($first['body'])[0] === 400, 'Return a decodable image');
    $again = $service->get('https://example.org/a.jpg', static function () use (&$budgets): void { $budgets++; });
    $check($again['cached'] && $again['body'] === $first['body'] && $downloads === 1 && $budgets === 1, 'Cache hit avoids download and miss rate budget');
    $service->get('https://example.org/b.jpg'); $service->get('https://example.org/c.jpg');
    $check($downloads === 3 && count(glob($dir . '/*.img')) === 2, 'URL changes generate anew and cache capacity is bounded');
    foreach (glob($dir . '/*.img') as $file) touch($file, time() - 90000);
    clearstatcache(); $service->get('https://example.org/c.jpg');
    $check($downloads === 4, 'Expired source is fetched again');
    $lock = fopen($dir . '/generation.lock', 'c'); flock($lock, LOCK_EX);
    try {
        try { $service->get('https://example.org/locked.jpg'); $check(false, 'Concurrent miss must be busy'); }
        catch (ThumbnailBusyException) { $check(true, 'Concurrent miss is rejected'); }
    } finally { fclose($lock); }

    $transparent = imagecreatetruecolor(30, 20);
    imagealphablending($transparent, false); imagesavealpha($transparent, true);
    imagefill($transparent, 0, 0, imagecolorallocatealpha($transparent, 0, 0, 0, 127));
    ob_start(); imagepng($transparent); $png = ob_get_clean(); imagedestroy($transparent);
    $small = $service->render($png);
    $decoded = imagecreatefromstring($small['body']);
    $check($small['width'] === 30 && $small['height'] === 20, 'Do not enlarge small images');
    $check(imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha'] === 127, 'Preserve transparency');
    imagedestroy($decoded);
    // Add a minimal EXIF APP1 orientation tag to an actual JPEG.
    foreach ([6 => [200, 400], 8 => [200, 400], 3 => [400, 200]] as $orientation => $expected) {
        $tiff = 'II' . pack('vVv', 42, 8, 1) . pack('vvVv', 0x112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        $oriented = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $result = $service->render($oriented);
        $check([$result['width'], $result['height']] === $expected, 'Apply EXIF orientation ' . $orientation);
        $check(!str_contains($result['body'], 'Exif'), 'Strip EXIF metadata');
    }
    $reject(static fn() => $service->render('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'Reject SVG');
    $reject(static fn() => $service->render('<html>not an image</html>'), 'Reject HTML');
    $reject(static fn() => $service->render(str_repeat('x', ThumbnailSource::MAX_BYTES + 1)), 'Reject oversized download');
    $huge = substr_replace($png, pack('NN', 4000, 4000), 16, 8);
    $reject(static fn() => $service->render($huge), 'Reject excessive pixels before decode');
    $failed = 0;
    $broken = new ThumbnailService(['cache_dir' => $dir], static function () use (&$failed): string { $failed++; return '<html>error</html>'; });
    $reject(static fn() => $broken->get('https://example.org/broken.jpg'), 'Reject invalid upstream response');
    $reject(static fn() => $broken->get('https://example.org/broken.jpg'), 'Negative-cache upstream failure');
    $check($failed === 1, 'Repeated failures do not refetch for 60 seconds');
    $received = [];
    $japaneseService = new ThumbnailService(['cache_dir' => $dir], static function (string $url) use (&$received, $jpeg): string {
        $received[] = $url;
        return $jpeg;
    });
    $japaneseService->get($japaneseUrl);
    $same = $japaneseService->get($encodedUrl);
    $check($received === [$encodedUrl], 'Downloader receives a correctly escaped URL exactly once');
    $check($same['cached'], 'Raw and escaped Japanese URLs share the cache');
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    if (is_dir($dir)) rmdir($dir);
}
echo "Thumbnail service: $checks checks passed.\n";
