<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

define('CMM_EXTERNAL_APP_CORS', true);
$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';

$method = Http::requireMethods(['POST']);

ActivityApi::run(function () use ($container): array {
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144) * 10);
    $input = Http::jsonInput($maxBytes);
    $appKey = ActivityApi::appKey($input);
    $external = Http::bearerRequested();
    $user = $external ? null : ActivityApi::requireProjectWriteAccess($container, $appKey);

    foreach (['creates', 'updates', 'deletes'] as $name) {
        if (isset($input[$name]) && (!is_array($input[$name]) || !array_is_list($input[$name]))) {
            throw new CommunityMapMaker\Activity\ActivityValidationException([$name => 'Must be a JSON array.']);
        }
    }
    $operations = [];
    foreach (['creates' => 'create', 'updates' => 'update', 'deletes' => 'delete'] as $field => $operation) {
        if (($input[$field] ?? []) !== []) $operations[] = 'batch:' . $operation;
    }
    $results = $container['activity']->batch(
        $appKey,
        $input['creates'] ?? [],
        $input['updates'] ?? [],
        $input['deletes'] ?? [],
        $user === null ? null : (int)$user['id'],
        $external ? fn() => $container['external_apps']->writeAccess($appKey, $operations) : null
    );
    return [200, ['status' => 'ok'] + $results];
});
