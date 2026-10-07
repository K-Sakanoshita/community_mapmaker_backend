<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
Http::requireMethod('GET');
// Older verification emails may link directly to this API. Browser navigation
// goes through the confirmation page without consuming the one-time token.
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document'
    || (stripos($accept, 'text/html') !== false && stripos($accept, 'application/json') === false)) {
    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    header('Location: ../verify-email.html?token=' . rawurlencode($token), true, 303);
    exit;
}
Http::run(function () use ($container): array {
    $container['auth']->verifyEmail((string)($_GET['token'] ?? ''));
    return [200, ['status' => 'ok', 'email_verified' => true]];
});
