<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\{ConsoleSession, ExternalAppException, Http};

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
$method = Http::requireMethods(['GET', 'POST']);

Http::run(function () use ($container, $method): array {
    $user = ConsoleSession::user($container);
    if (($user['console_auth_method'] ?? null) !== 'osm') {
        throw new ExternalAppException('authentication_required', 401);
    }
    $userId = (int)$user['id'];
    if ($method === 'GET') {
        $request = ConsoleSession::externalAuthorization();
        if ($request === null) throw new ExternalAppException('authorization_expired', 409);
        return [200, $container['external_apps']->consentDetails($request, $userId)];
    }
    $input = Http::jsonInput(1024);
    if (!is_string($input['nonce'] ?? null) || !is_bool($input['approve'] ?? null)) {
        throw new ExternalAppException('invalid_request', 400);
    }
    $request = ConsoleSession::externalAuthorization($input['nonce']);
    if ($request === null) throw new ExternalAppException('authorization_expired', 409);
    $return = $container['database']->run(fn() => $container['external_apps']->consent($request, $userId, $input['approve']));
    return [200, ['redirect_uri' => $return]];
});
