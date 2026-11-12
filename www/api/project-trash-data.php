<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\ProjectNotFoundException;
use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/AdminApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';

$method = Http::requireMethods(['GET', 'POST']);

ActivityApi::run(function () use ($container, $method): array {
    $admin = AdminApi::requireAdmin($container);
    if ($method === 'GET') return [200, $container['project_repo']->listDeleted()];

    $input = Http::jsonInput(4096);
    $appKey = trim((string)($input['app_key'] ?? ''));
    $action = (string)($input['action'] ?? '');
    $container['activity_schema']->assertKey($appKey, 'app_key');
    if (!in_array($action, ['restore', 'purge'], true)) {
        throw new ActivityValidationException(['action' => 'Unknown action.']);
    }
    if ($action === 'purge' && ($input['confirm_app_key'] ?? null) !== $appKey) {
        throw new ActivityValidationException(['confirm_app_key' => 'Enter the exact app key to permanently delete.']);
    }

    return $container['database']->run(function () use ($container, $admin, $appKey, $action): array {
        if ($action === 'restore') {
            if (!$container['project_repo']->restore($appKey)) throw new ProjectNotFoundException('Deleted project not found.');
            $container['audit_logs']->record((int)$admin['id'], 'restore_project', 'project', null, ['app_key' => $appKey]);
            return [200, ['status' => 'ok']];
        }
        $result = $container['project_repo']->purge($appKey);
        if ($result === null) throw new ProjectNotFoundException('Deleted project not found.');
        $container['audit_logs']->record((int)$admin['id'], 'purge_project', 'project', $result['id'], [
            'app_key' => $appKey, 'activities' => $result['activities'], 'assignments' => $result['assignments'],
        ]);
        return [200, ['status' => 'ok', 'deleted' => $result]];
    });
});
