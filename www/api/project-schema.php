<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
Http::requireMethod('POST');

// Validate a draft with the same rules as saving, without updating any records.
ActivityApi::run(function () use ($container): array {
    $user = ActivityApi::requireAuthentication($container);
    $app = is_string($_GET['app'] ?? null) ? trim($_GET['app']) : '';
    if ($app === '') throw new ActivityValidationException(['app' => 'App key is required.']);
    $container['project_access']->assertCanManage($user, $app);
    $container['project_service']->find($app);
    $limit = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144));
    $input = Http::jsonInput($limit);
    return [200, ['schema' => $container['project_service']->sanitizeSchema($input)]];
});
