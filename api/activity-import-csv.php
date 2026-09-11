<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
require_once dirname(__DIR__) . '/lib/AdminApi.php';

$method = Http::requireMethods(['POST']);

ActivityApi::run(function () use ($container): array {
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144) * 10);
    $input = Http::jsonInput($maxBytes);
    $appKey = ActivityApi::appKey($input);
    $admin = AdminApi::requireAdmin($container);

    $parsed = $container['csv_activity_import']->parse($appKey, (string)($input['csv'] ?? ''));
    $dryRun = ($input['dry_run'] ?? true) !== false;
    $previewFields = $dryRun ? (array)($parsed['schema_candidate']['fields'] ?? []) : null;
    $result = $container['activity']->import($appKey, $parsed['rows'], $dryRun, $previewFields, (int)$admin['id']);
    unset($parsed['rows']);
    return [200, ['status' => 'ok'] + $result + $parsed];
});
