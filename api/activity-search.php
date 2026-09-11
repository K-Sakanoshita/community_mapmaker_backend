<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';

Http::requireMethod('GET');

ActivityApi::run(function () use ($container): array {
    $appKey = ActivityApi::appKey();
    return [200, ['status' => 'ok'] + $container['activity_search']->search($appKey, $_GET)];
});
