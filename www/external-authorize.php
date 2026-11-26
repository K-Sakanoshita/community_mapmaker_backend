<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>アプリとの連携 | Community Map Maker</title>
  <link rel="stylesheet" href="theme.css">
  <link rel="stylesheet" href="external-apps.css?v=1">
  <script defer src="external-authorize.js?v=1"></script>
</head>
<body>
  <main class="external-page">
    <h1>アプリとの連携</h1>
    <p id="authorizationStatus" role="status">認証状態を確認しています…</p>
    <section id="appConsent" hidden>
      <dl>
        <dt>アプリ</dt><dd id="appName"></dd>
        <dt>ドメイン</dt><dd id="appOrigin"></dd>
        <dt>プロジェクト</dt><dd id="appProject"></dd>
        <dt>アカウント</dt><dd id="appUser"></dd>
      </dl>
      <h2>許可する操作</h2>
      <ul id="appScopes"></ul>
      <p id="ownerRestriction" hidden>投稿者権限では、更新・削除は自分の投稿に限られます。</p>
      <div class="external-actions">
        <button id="allowApp" class="primary" type="button">許可する</button>
        <button id="denyApp" type="button">許可しない</button>
      </div>
    </section>
    <p><a href="mypage.html">マイページ</a></p>
  </main>
</body>
</html>
