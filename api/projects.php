<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ProjectNotFoundException;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';

$method = Http::requireMethods(['GET', 'POST', 'PUT', 'DELETE']);

ActivityApi::run(function () use ($container, $method): array {
    $user = ActivityApi::requireAuthentication($container);
    $isAdmin = ($user['role'] ?? 'user') === 'admin';

    if ($method === 'GET') {
        $listMethod = ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') === '1' ? 'visibleConsoleProjects' : 'visibleProjects';
        $projects = $container['project_repo']->withActivityCounts($container['project_access']->$listMethod(
            $user,
            $container['project_service']->list()
        ));
        $appKey = isset($_GET['app']) ? trim((string)$_GET['app']) : '';
        if ($appKey === '') return [200, $projects];
        foreach ($projects as $project) {
            if ($project['app_key'] === $appKey) return [200, $project];
        }
        throw new ProjectNotFoundException('Project not found.');
    }

    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144));
    $input = $method === 'DELETE' ? [] : Http::jsonInput($maxBytes);

    if ($method === 'POST') {
        $container['project_access']->assertCanUseConsole($user);
        $created = $container['database']->run(function () use ($container, $input, $user, $isAdmin): array {
            $project = $container['project_service']->create($input, (int)$user['id']);
            if (!$isAdmin) $container['project_access']->assignCreatedProject($user, $project);
            return $project;
        });
        return [201, $created + ['access_role' => $isAdmin ? 'admin' : 'project_admin', 'activity_count' => 0]];
    }

    $appKey = isset($_GET['app']) ? trim((string)$_GET['app']) : trim((string)($input['app_key'] ?? ''));
    if ($appKey === '') {
        throw new CommunityMapMaker\Activity\ActivityValidationException(['app_key' => 'App key is required.']);
    }

    if ($method === 'PUT') {
        $container['project_access']->assertCanManage($user, $appKey);
        $updated = $container['project_service']->update($appKey, $input);
        $updated = $container['project_repo']->withActivityCounts([$updated])[0];
        return [200, $updated + ['access_role' => $isAdmin ? 'admin' : 'project_admin']];
    }

    $container['project_access']->assertCanManage($user, $appKey);
    $container['project_access']->assertCanDelete($user, $container['project_service']->find($appKey));
    $container['project_service']->delete($appKey);
    return [200, ['status' => 'ok']];
});
