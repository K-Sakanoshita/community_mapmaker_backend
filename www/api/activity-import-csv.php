<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/AdminApi.php';

$method = Http::requireMethods(['POST']);

ActivityApi::run(function () use ($container): array {
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144) * 10);
    $input = Http::jsonInput($maxBytes);
    $appKey = ActivityApi::appKey($input);
    $admin = AdminApi::requireAdmin($container);

    $parsed = $container['csv_activity_import']->parse($appKey, (string)($input['csv'] ?? ''));
    $dryRun = ($input['dry_run'] ?? true) !== false;
    $previewFields = $dryRun ? (array)($parsed['schema_candidate']['fields'] ?? []) : null;
    try {
        $result = $container['activity']->import($appKey, $parsed['rows'], $dryRun, $previewFields, (int)$admin['id'], true);
    } catch (CommunityMapMaker\Activity\ActivityValidationException $error) {
        throw new CommunityMapMaker\Activity\ActivityValidationException(
            CommunityMapMaker\Activity\CsvActivityImport::errorsWithLineNumbers($error->errors, $parsed['row_lines'])
        );
    }
    if (isset($result['coordinate_lookup'])) {
        foreach ($result['coordinate_lookup']['unresolved'] as &$missing) {
            $missing['line'] = $parsed['row_lines'][$missing['row']];
            unset($missing['row']);
        }
        unset($missing);
    }
    unset($parsed['rows'], $parsed['row_lines']);
    return [200, ['status' => 'ok'] + $result + $parsed];
});
