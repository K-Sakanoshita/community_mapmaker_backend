<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OpenStreetMap | Community Map Maker</title>
  <script src="../osm-callback.js?v=4" defer></script>
</head>
<body>
  <main>
    <h1>OpenStreetMap</h1>
    <p id="osmStatus" role="status">OpenStreetMap の認証を確認しています…</p>
    <p id="osmRegistrationLink" hidden><a href="../register.html">OpenStreetMap で登録する</a></p>
    <p><a id="osmReturn" href="../admin/">ログイン画面へ戻る</a></p>
    <noscript>認証を完了するには JavaScript が必要です。 JavaScript is required to complete sign-in.</noscript>
  </main>
</body>
</html>
