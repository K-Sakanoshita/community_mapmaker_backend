<?php
declare(strict_types=1);

// Run only against a disposable local DB/web pair; fixtures are removed in finally.
if (getenv('CMM_LOCAL_THUMBNAIL_TEST') !== '1') { fwrite(STDERR, "Set CMM_LOCAL_THUMBNAIL_TEST=1 in the local test web container.\n"); exit(1); }
require_once dirname(__DIR__) . '/lib/ThumbnailService.php';
use CommunityMapMaker\Activity\ThumbnailService;
use CommunityMapMaker\Auth\RateLimiter;
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/RateLimiter.php';
$config = require getenv('CMM_AUTH_CONFIG');
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$app = 'thumb-http-' . bin2hex(random_bytes(5));
$url = 'https://example.org/' . $app . '.jpg';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$request = static function (string $query, string $method = 'GET', ?array $body = null, ?string $user = null, array $headers = []): array {
    $curl = curl_init('http://127.0.0.1:8080/api/thumbnail.php' . $query);
    $received = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$received): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $received[strtolower($key)] = trim($value); }
            return strlen($line);
        }]);
    if ($user !== null) curl_setopt($curl, CURLOPT_USERPWD, $user . ':LocalTestPass!');
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    try {
        $data = curl_exec($curl);
        if ($data === false) throw new RuntimeException(curl_error($curl));
        return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data, $received];
    } finally { curl_close($curl); }
};
$image = imagecreatetruecolor(800, 400);
ob_start(); imagejpeg($image); $source = ob_get_clean(); imagedestroy($image);
$cache = new ThumbnailService([], static fn() => $source);
$cache->get($url);
$japaneseUrl = 'https://example.org/' . $app . '/公園 写真.jpg?名前=写真&token=a%2Fb';
$encodedJapaneseUrl = \CommunityMapMaker\Activity\ThumbnailSource::normalizeUrl($japaneseUrl);
$cache->get($japaneseUrl);
$update = static function (array $data) use ($pdo, $app): void {
    $pdo->prepare('UPDATE activities SET data_json=? WHERE app_key=?')->execute([json_encode($data), $app]);
};
$cacheDir = sys_get_temp_dir() . '/cmm-thumbnails-v1';
$path = '?' . http_build_query(['app' => $app, 'id' => 'photo/1']);
try {
    $pdo->prepare('INSERT INTO projects (app_key,project_name,schema_json,enabled,created_at,updated_at) VALUES (?, ?, ?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
        ->execute([$app, 'Thumbnail HTTP test', json_encode(['fields' => ['image' => ['type' => 'image'], 'name' => ['type' => 'text']]])]);
    $pdo->prepare('INSERT INTO activities (app_key,activity_key,osmid,data_json,created_at,updated_at) VALUES (?, ?, ?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
        ->execute([$app, 'photo/1', 'way/1', json_encode(['image' => $url])]);
    [$status, $body, $headers] = $request($path);
    $check($status === 200 && $headers['content-type'] === 'image/webp' && getimagesizefromstring($body)[0] === 400, 'Anonymous GET returns generated image');
    $check($headers['cache-control'] === 'private, no-cache' && $headers['x-content-type-options'] === 'nosniff', 'Revalidate every request and prevent MIME sniffing');
    [$status, $body] = $request($path, 'GET', null, null, ['If-None-Match: ' . $headers['etag']]);
    $check($status === 304 && $body === '', 'Conditional GET returns 304');
    $check($request($path . '&field=name')[0] === 422, 'Non-image fields rejected');
    $check($request('?app[]=' . $app . '&id=photo%2F1')[0] === 422, 'Array app parameter rejected');
    $check($request($path . '&index[]=0')[0] === 422, 'Array index rejected');
    $check($request('?app=' . $app)[0] === 422, 'Missing Activity ID rejected');
    $check($request('?app=unknown-thumbnail-app&id=photo%2F1')[0] === 404, 'Unknown project rejected');
    $check($request('?app=' . $app . '&id=missing')[0] === 404, 'Missing Activity rejected');
    $check($request($path, 'DELETE')[0] === 405, 'Unsupported method rejected');
    $check($request('', 'POST', ['app' => $app, 'url' => $url])[0] === 401, 'Arbitrary URL POST requires authentication');
    $check($request('', 'POST', ['app' => $app, 'url' => $url], 'localviewer')[0] === 403, 'Viewer cannot generate arbitrary URL');
    [$status, $body] = $request('', 'POST', ['app' => $app, 'url' => $url], 'localadmin');
    $check($status === 200 && getimagesizefromstring($body)[0] === 400, 'Authenticated POST returns image');
    $relative = '../' . $app . '.jpg';
    $update(['image' => $relative]);
    $check($request($path)[0] === 422, 'GET relative path requires configured frontend URL');
    $check($request('', 'POST', ['app' => $app, 'url' => $relative], 'localadmin')[0] === 422, 'POST relative path requires configured frontend URL');
    $pdo->prepare('UPDATE projects SET frontend_url=? WHERE app_key=?')->execute(['https://example.org/map/index.html?lang=ja#map', $app]);
    foreach ([$relative, '/' . $app . '.jpg', '//example.org/' . $app . '.jpg'] as $imageUrl) {
        $update(['image' => $imageUrl]);
        [$status, $imageBody] = $request($path);
        $check($status === 200 && @getimagesizefromstring($imageBody)[0] === 400, 'GET resolves stored relative path using project frontend URL');
        [$status, $imageBody] = $request('', 'POST', ['app' => $app, 'url' => $imageUrl], 'localadmin');
        $check($status === 200 && @getimagesizefromstring($imageBody)[0] === 400, 'POST resolves relative path using project frontend URL');
    }
    $update(['image' => ['../' . $app . '.jpg']]);
    $check($request($path . '&index=0')[0] === 200, 'Resolve relative image in array');
    $update(['image' => '//127.0.0.1/private']);
    $check($request($path)[0] === 502, 'Resolved relative path still blocks local-network source');

    foreach ([$japaneseUrl, $encodedJapaneseUrl] as $imageUrl) {
        $update(['image' => $imageUrl]);
        [$status, $imageBody] = $request($path);
        $check($status === 200 && @getimagesizefromstring($imageBody)[0] === 400, 'GET accepts raw or escaped Japanese image URL');
        [$status, $imageBody] = $request('', 'POST', ['app' => $app, 'url' => $imageUrl], 'localadmin');
        $check($status === 200 && @getimagesizefromstring($imageBody)[0] === 400, 'POST accepts raw or escaped Japanese image URL');
    }
    $check($request('', 'POST', ['app' => $app, 'url' => 'file:///etc/passwd'], 'localadmin')[0] === 422, 'Reject unsupported URL scheme');
    $update(['image' => [$url, $url]]);
    $check($request($path . '&index=1')[0] === 200, 'Select URL from an image array');
    $check($request($path . '&index=2')[0] === 404, 'Missing image array item rejected');
    $update(['image' => 'File:Park.jpg']);
    $check($request($path)[0] === 422, 'Non-URL image references rejected explicitly');
    $update(['image' => 'http://127.0.0.1/private']);
    $check($request($path)[0] === 502, 'Local-network source blocked through HTTP');
    $update(['image' => $url]);
    $locks = [];
    for ($slot = 0; $slot < 2; $slot++) {
        $locks[] = fopen($cacheDir . '/generation-' . $slot . '.lock', 'c+');
        flock($locks[$slot], LOCK_EX);
    }
    try {
        $check($request('', 'POST', ['app' => $app, 'url' => $url . '?busy=1'], 'localadmin')[0] === 503, 'Concurrent generation returns 503');
    } finally { foreach ($locks as $lock) fclose($lock); }
    $subject = '127.0.0.1';
    $limiter = new RateLimiter($pdo, $config['auth']['rate_limit_secret']);
    for ($i = 0; $i < 30; $i++) $limiter->hit('thumbnail_client', $subject, 30, 60);
    [$status, $body, $headers] = $request('', 'POST', ['app' => $app, 'url' => $url . '?limit=1'], 'localadmin');
    $check($status === 429 && $headers['retry-after'] === '60', 'Cold generation has a DB-backed request limit');
    $check($request($path)[0] === 200, 'Cache hits remain available after rate limiting');
    $pdo->prepare('UPDATE activities SET is_deleted=1 WHERE app_key=?')->execute([$app]);
    $check($request($path)[0] === 404, 'Deleted Activity is hidden despite cached thumbnail');
    $pdo->prepare('UPDATE activities SET is_deleted=0 WHERE app_key=?')->execute([$app]);
    $pdo->prepare('UPDATE projects SET is_deleted=1 WHERE app_key=?')->execute([$app]);
    $check($request($path)[0] === 404, 'Deleted project is hidden despite cached thumbnail');
} finally {
    $pdo->prepare('DELETE FROM activities WHERE app_key=?')->execute([$app]);
    $pdo->prepare('DELETE FROM projects WHERE app_key=?')->execute([$app]);
    $pdo->prepare('DELETE FROM auth_rate_limits WHERE action_key=? AND subject_hash=?')->execute(['thumbnail_client', hash_hmac('sha256', '127.0.0.1', $config['auth']['rate_limit_secret'])]);
    foreach ([$url, $encodedJapaneseUrl, 'http://127.0.0.1/private'] as $key) {
        $hash = hash('sha256', '400:80:webp:' . $key);
        foreach (['.img', '.fail'] as $suffix) { $file = $cacheDir . '/' . $hash . $suffix; if (is_file($file)) unlink($file); }
    }
}
echo "Thumbnail HTTP: $checks checks passed.\n";
