<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
Http::requireMethod('GET');

ActivityApi::run(function () use ($container): array {
    $public = [];
    foreach ($container['project_service']->list() as $project) {
        if (!$project['frontend_public'] || !$project['frontend_url']) continue;
        $public[] = [
            'app_key' => $project['app_key'],
            'project_name' => $project['project_name'],
            'frontend_url' => $project['frontend_url'],
        ];
    }
    return [200, $public];
});
