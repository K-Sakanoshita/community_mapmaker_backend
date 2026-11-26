<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\ConsoleSession;
use CommunityMapMaker\Auth\DuplicateIdentityException;
use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\ValidationException;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
$method = Http::requireMethods(['GET', 'POST', 'PATCH', 'DELETE']);

ActivityApi::run(function () use ($container, $method): array {
    if ($method !== 'GET' && ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') {
        return [403, ['code' => 'console_header_required']];
    }
    if ($method === 'DELETE') {
        ConsoleSession::destroy();
        return [200, ['status' => 'ok']];
    }
    if ($method === 'PATCH') {
        $user = ConsoleSession::user($container);
        if ($user === null) return [401, ['code' => 'authentication_required']];
        $input = Http::jsonInput(1024);
        if (($input['current_userid'] ?? null) !== $user['userid']) {
            return [409, ['code' => 'userid_changed']];
        }
        $value = $input['userid'] ?? null;
        try {
            [$userid, $normalized] = $container['auth']->validateUserid(is_string($value) ? $value : '');
        } catch (ValidationException $error) {
            return [422, ['code' => 'validation_failed', 'errors' => $error->errors]];
        }
        if ($userid !== $user['userid']) {
            try {
                $renamed = $container['database']->run(fn() => $container['user_repo']->renameUserid($user, $userid, $normalized));
            } catch (DuplicateIdentityException) {
                return [409, ['code' => 'userid_taken']];
            }
            if ($renamed === null) return [409, ['code' => 'userid_changed']];
            ConsoleSession::establish($renamed, $user['console_auth_method']);
            $user = $renamed;
        }
    } else {
        $user = ActivityApi::requireAuthentication($container);
        $container['project_access']->assertCanUseConsole($user);
        if ($method === 'POST') {
            ConsoleSession::establish($container['user_repo']->findById((int)$user['id']), $user['console_auth_method'] ?? 'password');
        }
    }
    $identity = [
        'id' => (int)$user['id'],
        'userid' => (string)$user['userid'],
        'role' => (string)($user['role'] ?? 'user'),
    ];
    if ($method === 'PATCH' || ($method === 'GET' && ($_GET['summary'] ?? '') === '1')) {
        return [200, ['user' => $identity]];
    }
    $projects = $container['project_repo']->withPublicSummaries($container['project_access']->visibleConsoleProjects(
        $user,
        $container['project_service']->list()
    ));
    return [200, [
        'user' => $identity,
        'projects' => $projects,
    ]];
});
