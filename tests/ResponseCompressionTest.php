<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\ResponseCompression;

require_once dirname(__DIR__) . '/lib/ResponseCompression.php';
$payload = json_encode(['text' => str_repeat('公園の画像と説明 / example ', 200)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (PHP_SAPI === 'cli-server') {
    $mode = $_GET['mode'] ?? 'json';
    if ($mode === 'handler') ob_start('ob_gzhandler');
    ResponseCompression::start();
    header('Vary: Origin');
    header('Content-Type: ' . ($mode === 'csv' ? 'text/csv; charset=UTF-8' : ($mode === 'binary' ? 'application/octet-stream' : 'application/json; charset=UTF-8')));
    if ($mode === 'error') http_response_code(422);
    if ($mode === 'no-transform') header('Cache-Control: no-transform');
    if ($mode === 'encoded') { header('Content-Encoding: gzip'); echo gzencode($payload); }
    elseif ($mode === 'small') echo '{}';
    elseif ($mode === 'csv') echo "\xef\xbb\xbfid,body\r\n1,\"" . str_repeat('日本語の説明', 200) . "\"\r\n";
    else echo $payload;
    exit;
}

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
foreach ([['', false], ['gzip', true], ['GZip', true], ['br, gzip', true], ['identity', false], ['br', false],
    ['gzip;q=0', false], ['gzip;q=0.0', false], ['gzip; q=0.5', true], ['*;q=1', true], ['*;q=0', false],
    ['gzip;q=0, *;q=1', false], ['gzip;q=1, *;q=0', true], ['gzip;q=2', false], ['gzip;q=oops', false],
    ['gzip;q=0, gzip;q=1', false], ['xgzip', false], ['gzip;q=0.000', false], ['gzip;q=0.001', true]] as [$header, $accepted]) {
    $check(ResponseCompression::acceptsGzip($header) === $accepted, 'Accept-Encoding negotiation: ' . $header);
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false); fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'cmm-compression-test-');
$server = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($server)) throw new RuntimeException('Cannot start compression test server.');
fclose($pipes[0]);
$request = static function (?string $encoding, string $mode = 'json', string $method = 'GET') use ($address): array {
    $headers = [];
    $curl = curl_init('http://' . $address . '/?mode=' . $mode);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => $encoding === null ? [] : ['Accept-Encoding: ' . $encoding],
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower($key)][] = trim($value); }
            return strlen($line);
        }]);
    try {
        $body = curl_exec($curl);
        if ($body === false) throw new RuntimeException(curl_error($curl));
        return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $headers, $body];
    } finally { curl_close($curl); }
};
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(10000);
    }
    $check($ready, 'Test server starts');
    [$status, $headers, $body] = $request('gzip');
    $check($status === 200 && ($headers['content-encoding'][0] ?? '') === 'gzip', 'Large JSON is gzip encoded');
    $check(gzdecode($body) === $payload && strlen($body) < strlen($payload), 'Decoded JSON is byte-identical and smaller');
    $check((int)$headers['content-length'][0] === strlen($body), 'Content-Length describes compressed bytes');
    $vary = strtolower(implode(',', $headers['vary']));
    $check(str_contains($vary, 'origin') && str_contains($vary, 'accept-encoding'), 'Preserve Vary: Origin while adding Accept-Encoding');
    foreach ([null, 'identity', 'br', 'gzip;q=0', 'gzip;q=0, *;q=1'] as $encoding) {
        [$status, $headers, $body] = $request($encoding);
        $check($status === 200 && !isset($headers['content-encoding']) && $body === $payload, 'Uncompressed JSON for ' . ($encoding ?? 'missing header'));
        $check(str_contains(strtolower(implode(',', $headers['vary'])), 'accept-encoding'), 'Uncompressed variant includes Vary');
    }
    [$status, $headers, $plainCsv] = $request('identity', 'csv');
    [$status, $headers, $body] = $request('gzip', 'csv');
    $check(($headers['content-encoding'][0] ?? '') === 'gzip' && gzdecode($body) === $plainCsv, 'CSV including BOM and line endings round trips');
    foreach (['encoded', 'handler'] as $mode) {
        [$status, $headers, $body] = $request('gzip', $mode);
        $check(($headers['content-encoding'][0] ?? '') === 'gzip' && gzdecode($body) === $payload, 'Avoid double compression: ' . $mode);
    }
    foreach (['error', 'binary', 'no-transform', 'small'] as $mode) {
        [$status, $headers, $body] = $request('gzip', $mode);
        $check(!isset($headers['content-encoding']) && $body === ($mode === 'small' ? '{}' : $payload), 'Do not compress ' . $mode);
    }
    [$status, $headers, $body] = $request('gzip', 'json', 'POST');
    $check(!isset($headers['content-encoding']) && $body === $payload, 'Only GET responses are eligible');
} finally {
    proc_terminate($server); proc_close($server); unlink($log);
}
echo "Response compression: $checks checks passed.\n";
