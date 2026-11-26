<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\{ConsoleSession, ExternalAppException, Http};

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
$method = Http::requireMethods(['GET', 'POST', 'PUT', 'DELETE']);

Http::run(function () use ($container, $method): array {
    $user = ConsoleSession::user($container);
    if ($user === null) throw new ExternalAppException('authentication_required', 401);
    if ($user['role'] !== 'admin') throw new ExternalAppException('admin_required', 403);
    if ($method === 'GET') return [200, ['apps' => $container['external_apps']->list()]];
    if ($method === 'DELETE') {
        $clientId = $_GET['client_id'] ?? null;
        if (!is_string($clientId) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $clientId)) {
            throw new ExternalAppException('invalid_request', 400);
        }
        $container['database']->run(function () use ($container, $user, $clientId): void {
            $container['external_apps']->stop($clientId);
            $container['audit_logs']->record((int)$user['id'], 'external_app.stop', 'external_app', null, ['client_id' => $clientId]);
        });
        return [200, ['status' => 'ok']];
    }
    $input = Http::jsonInput(8192);
    $app = $container['database']->run(function () use ($container, $method, $user, $input): array {
        $app = $container['external_apps']->save($input, $method === 'POST');
        $container['audit_logs']->record((int)$user['id'], $method === 'POST' ? 'external_app.create' : 'external_app.update',
            'external_app', null, ['client_id' => $app['client_id'], 'app_key' => $app['app_key']]);
        return $app;
    });
    return [$method === 'POST' ? 201 : 200, ['app' => $app]];
});
