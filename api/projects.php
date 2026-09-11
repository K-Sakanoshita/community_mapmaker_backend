<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
require_once dirname(__DIR__) . '/lib/AdminApi.php';

$method = Http::requireMethods(['GET', 'POST', 'PUT', 'DELETE']);

if ($method === 'GET') {
    ActivityApi::run(function () use ($container): array {
        AdminApi::requireAdmin($container);
        $appKey = isset($_GET['app']) ? trim((string)$_GET['app']) : '';
        if ($appKey !== '') {
            $project = $container['project_service']->find($appKey);
            return [200, $project];
        }
        $projects = $container['project_service']->list();
        return [200, $projects];
    });
}

ActivityApi::run(function () use ($container, $method): array {
    AdminApi::requireAdmin($container);
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144));
    $input = $method === 'DELETE' ? [] : Http::jsonInput($maxBytes);

    if ($method === 'POST') {
        $created = $container['project_service']->create($input);
        return [201, $created];
    }

    $appKey = isset($_GET['app']) ? trim((string)$_GET['app']) : trim((string)($input['app_key'] ?? ''));
    if ($appKey === '') {
        throw new CommunityMapMaker\Activity\ActivityValidationException(['app_key' => 'App key is required.']);
    }

    if ($method === 'PUT') {
        $updated = $container['project_service']->update($appKey, $input);
        return [200, $updated];
    }

    $container['project_service']->delete($appKey);
    return [200, ['status' => 'ok']];
});
