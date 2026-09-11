<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
require_once dirname(__DIR__) . '/lib/AdminApi.php';
Http::requireMethod('POST');

ActivityApi::run(function () use ($container): array {
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144));
    $input = Http::jsonInput($maxBytes);
    $appKey = ActivityApi::appKey($input);
    $admin = AdminApi::requireAdmin($container);
    $rows = $input['rows'] ?? null;
    if (!is_array($rows)) {
        throw new CommunityMapMaker\Activity\ActivityValidationException(['rows' => 'An array of activities is required.']);
    }
    $dryRun = ($input['dry_run'] ?? true) !== false;
    return [200, ['status' => 'ok'] + $container['activity']->import($appKey, $rows, $dryRun, null, (int)$admin['id'])];
});
