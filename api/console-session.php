<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
Http::requireMethods(['GET', 'POST', 'DELETE']);

ActivityApi::run(function () use ($container): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') {
        return [403, ['code' => 'console_header_required']];
    }
    if ($method === 'DELETE') {
        \CommunityMapMaker\Auth\ConsoleSession::destroy();
        return [200, ['status' => 'ok']];
    }
    $user = ActivityApi::requireAuthentication($container);
    if ($method === 'POST') {
        \CommunityMapMaker\Auth\ConsoleSession::establish($container['user_repo']->findById((int)$user['id']));
    }
    $isAdmin = ($user['role'] ?? 'user') === 'admin';
    $projects = $container['project_access']->visibleProjects(
        $user,
        $container['project_service']->list(!$isAdmin)
    );
    return [200, [
        'user' => [
            'id' => (int)$user['id'],
            'userid' => (string)$user['userid'],
            'role' => (string)($user['role'] ?? 'user'),
        ],
        'projects' => $projects,
    ]];
});
