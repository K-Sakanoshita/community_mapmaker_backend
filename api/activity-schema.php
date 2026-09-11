<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
Http::requireMethod('GET');

ActivityApi::run(function () use ($container): array {
    $appKey = ActivityApi::appKey();
    if ($appKey === '') {
        return [200, ['apps' => $container['activity_schema']->appKeys()]];
    }
    return [200, ['app' => $appKey] + $container['activity_schema']->get($appKey)];
});
