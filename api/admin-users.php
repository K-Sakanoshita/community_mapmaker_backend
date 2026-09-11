<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminApi.php';

$method = Http::requireMethods(['GET', 'POST', 'PUT']);

AdminApi::run(function () use ($container, $method): array {
    $admin = AdminApi::requireAdmin($container);
    if ($method === 'GET') {
        if (isset($_GET['id']) && (string)$_GET['id'] !== '') {
            return [200, $container['admin_users']->find(AdminApi::userId())];
        }
        return [200, $container['admin_users']->list($_GET)];
    }

    $input = Http::jsonInput(262144);
    if ($method === 'PUT') {
        return [200, $container['admin_users']->update(AdminApi::userId($input), $input, (int)$admin['id'])];
    }

    $action = trim((string)($input['action'] ?? 'create'));
    $client = Http::clientIdentifier($container['auth_config']);
    if ($action === 'create') {
        return [201, $container['admin_users']->create($input, (int)$admin['id'], $client)];
    }
    $id = AdminApi::userId($input);
    if ($action === 'resend_verification') {
        return [200, ['status' => 'ok'] + $container['admin_users']->resendVerification($id, (int)$admin['id'], $client)];
    }
    if ($action === 'send_password_reset') {
        return [200, ['status' => 'ok'] + $container['admin_users']->sendPasswordReset($id, (int)$admin['id'], $client)];
    }
    if ($action === 'set_password') {
        return [200, ['status' => 'ok', 'user' => $container['admin_users']->setPassword($id, $input, (int)$admin['id'])]];
    }
    throw new CommunityMapMaker\Auth\ValidationException(['action' => 'Unknown admin action.']);
});
