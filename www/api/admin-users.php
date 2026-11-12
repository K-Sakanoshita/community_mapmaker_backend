<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/AdminApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/AdminUserDeletion.php';

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
    if (in_array($action, ['soft_delete', 'restore', 'purge'], true)) {
        if (($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') return [403, ['code' => 'console_header_required']];
        try {
            $deletion = new CommunityMapMaker\Auth\AdminUserDeletion($container['database']->pdo(), $container['audit_logs'], (string)$container['auth_config']['rate_limit_secret']);
            return [200, $deletion->{['soft_delete'=>'delete', 'restore'=>'restore', 'purge'=>'purge'][$action]}((int)$admin['id'], $id, $input['confirm_userid'] ?? null)];
        } catch (CommunityMapMaker\Auth\AdminUserDeletionDenied $error) {
            return [$error->getMessage() === 'admin_required' ? 403 : 409, ['code' => $error->getMessage()]];
        }
    }
    if ($action === 'add_email') {
        return [200, $container['admin_users']->addEmail($id, $input, (int)$admin['id'], $client)];
    }
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
