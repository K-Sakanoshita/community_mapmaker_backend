<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';

$method = Http::requireMethods(['POST']);

ActivityApi::run(function () use ($container): array {
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144) * 10);
    $input = Http::jsonInput($maxBytes);
    $appKey = ActivityApi::appKey($input);
    $user = ActivityApi::requireProjectWriteAccess($container, $appKey);

    foreach (['creates', 'updates', 'deletes'] as $name) {
        if (isset($input[$name]) && (!is_array($input[$name]) || !array_is_list($input[$name]))) {
            throw new CommunityMapMaker\Activity\ActivityValidationException([$name => 'Must be a JSON array.']);
        }
    }
    $results = $container['activity']->batch(
        $appKey,
        $input['creates'] ?? [],
        $input['updates'] ?? [],
        $input['deletes'] ?? [],
        (int)$user['id']
    );
    return [200, ['status' => 'ok'] + $results];
});
