<?php
declare(strict_types=1);

// Run in a disposable local DB/web pair. Fixture records are removed in finally.
if (getenv('CMM_LOCAL_COMPRESSION_TEST') !== '1') { fwrite(STDERR, "Set CMM_LOCAL_COMPRESSION_TEST=1 in the local test web container.\n"); exit(1); }
$config = require getenv('CMM_AUTH_CONFIG');
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$app = 'compression-http-' . bin2hex(random_bytes(5));
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$request = static function (string $query, ?string $encoding, string $method = 'GET') use ($app): array {
    $curl = curl_init('http://127.0.0.1:8080/api/activities.php?app=' . $app . $query);
    $headers = [];
    $outgoing = ['Origin: http://127.0.0.1:8080'];
    if ($encoding !== null) $outgoing[] = 'Accept-Encoding: ' . $encoding;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $outgoing,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower($key)][] = trim($value); }
            return strlen($line);
        }]);
    if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['app' => $app, 'osmid' => 'way/1', 'body' => 'test']));
    try {
        $body = curl_exec($curl);
        if ($body === false) throw new RuntimeException(curl_error($curl));
        return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $headers, $body];
    } finally { curl_close($curl); }
};
try {
    $pdo->prepare('INSERT INTO projects (app_key,project_name,schema_json,enabled,created_at,updated_at) VALUES (?, ?, ?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
        ->execute([$app, 'Compression HTTP test', json_encode(['fields' => ['body' => ['type' => 'textarea']]])]);
    $insert = $pdo->prepare('INSERT INTO activities (app_key,activity_key,osmid,data_json,created_at,updated_at) VALUES (?, ?, ?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    for ($i = 0; $i < 6; $i++) $insert->execute([$app, 'test/' . $i, 'way/1', json_encode(['body' => $i === 0 ? 'short' : str_repeat("公園の説明と写真 / 改行\n", 100)])]);
    [$status, $plainHeaders, $plain] = $request('&limit=100', 'identity');
    $check($status === 200 && !isset($plainHeaders['content-encoding']) && count(json_decode($plain, true, 512, JSON_THROW_ON_ERROR)) === 6, 'Identity response retains all six Activities');
    [$status, $headers, $body] = $request('&limit=100', 'gzip');
    $check($status === 200 && ($headers['content-encoding'][0] ?? '') === 'gzip', 'Activity JSON is compressed');
    $check(gzdecode($body) === $plain && strlen($body) < strlen($plain), 'Compressed and plain Activity JSON are byte-identical after decoding');
    $check((int)$headers['content-length'][0] === strlen($body), 'Compressed Content-Length is correct');
    $vary = strtolower(implode(',', $headers['vary']));
    $check(str_contains($vary, 'origin') && str_contains($vary, 'accept-encoding'), 'CORS Vary and encoding Vary coexist');
    $check(($headers['access-control-allow-origin'][0] ?? '') === 'http://127.0.0.1:8080', 'CORS response is retained');
    foreach ([null, 'br', 'gzip;q=0, *;q=1'] as $encoding) {
        [$status, $headers, $body] = $request('&limit=100', $encoding);
        $check($status === 200 && !isset($headers['content-encoding']) && $body === $plain, 'Unsupported or declined gzip remains plain');
    }
    [$status, $plainHeaders, $csv] = $request('&format=csv&limit=100', 'identity');
    [$status, $headers, $body] = $request('&format=csv&limit=100', 'gzip');
    $check($status === 200 && ($headers['content-encoding'][0] ?? '') === 'gzip' && gzdecode($body) === $csv, 'CSV export is byte-identical after decoding');
    $check(str_starts_with($csv, "\xef\xbb\xbf") && $headers['content-disposition'] === $plainHeaders['content-disposition'], 'CSV BOM and download filename are preserved');
    [$status, $headers, $body] = $request('&id=test%2F0', 'gzip');
    $check($status === 200 && !isset($headers['content-encoding']) && json_decode($body, true)['body'] === 'short', 'Small Activity stays uncompressed');
    [$status, $headers, $body] = $request('&limit=0', 'gzip');
    $check($status === 422 && !isset($headers['content-encoding']) && json_decode($body, true)['code'] === 'validation_failed', 'Validation error remains unchanged');
    [$status, $headers, $body] = $request('', 'gzip', 'POST');
    $check($status === 401 && !isset($headers['content-encoding']), 'Write response remains uncompressed');
} finally {
    $pdo->prepare('DELETE FROM activities WHERE app_key=?')->execute([$app]);
    $pdo->prepare('DELETE FROM projects WHERE app_key=?')->execute([$app]);
}
echo "Activity compression HTTP: $checks checks passed.\n";
