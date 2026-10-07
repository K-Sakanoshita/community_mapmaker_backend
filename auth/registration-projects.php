<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
Http::requireMethod('GET');
Http::run(function () use ($container): array {
    $projects = [];
    foreach ($container['project_repo']->list(true) as $project) {
        if (!$project['enabled'] || !$project['frontend_public']) continue;
        $projects[] = [
            'app_key' => $project['app_key'],
            'project_name' => $project['project_name'],
        ];
    }
    return [200, ['projects' => $projects]];
});
