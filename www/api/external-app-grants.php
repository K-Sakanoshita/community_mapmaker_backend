<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\{ConsoleSession, ExternalAppException, Http};

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
$method = Http::requireMethods(['GET', 'DELETE']);

Http::run(function () use ($container, $method): array {
    $user = ConsoleSession::user($container);
    if ($user === null) throw new ExternalAppException('authentication_required', 401);
    $userId = (int)$user['id'];
    if ($method === 'GET') return [200, ['apps' => $container['external_apps']->grants($userId)]];
    $clientId = $_GET['client_id'] ?? null;
    if (!is_string($clientId) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $clientId)) {
        throw new ExternalAppException('invalid_request', 400);
    }
    $container['database']->run(fn() => $container['external_apps']->revoke($clientId, $userId));
    return [200, ['status' => 'ok']];
});
