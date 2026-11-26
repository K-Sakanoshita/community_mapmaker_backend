<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\ConsoleSession;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
$method = Http::requireMethods(['GET', 'POST']);

Http::run(function () use ($container, $method): array {
    if (($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') {
        return [403, ['code' => 'console_header_required']];
    }
    $account = ConsoleSession::user($container);
    if ($account === null) return [401, ['code' => 'authentication_required']];
    if ($method === 'GET') {
        return [200, [
            'requires_current_password' => $account['console_auth_method'] !== 'osm',
            'password_min_length' => max(8, (int)($container['auth_config']['password_min_length'] ?? 8)),
            'password_max_bytes' => PASSWORD_DEFAULT === PASSWORD_BCRYPT ? 72 : 4096,
        ]];
    }
    $updated = $container['auth']->setConsolePassword($account, Http::jsonInput(16384), $container['user_repo']);
    if ($updated === null) return [409, ['code' => 'account_changed']];
    ConsoleSession::establish($updated, $account['console_auth_method']);
    return [200, ['status' => 'ok']];
});
