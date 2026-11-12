<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
Http::requireMethod('GET');

ActivityApi::run(function () use ($container): array {
    $public = [];
    foreach ($container['project_service']->list() as $project) {
        if (!$project['frontend_public'] || !$project['frontend_url']) continue;
        $public[] = [
            'app_key' => $project['app_key'],
            'project_name' => $project['project_name'],
            'frontend_url' => $project['frontend_url'],
            'recruiting' => (bool)($project['recruiting'] ?? false),
        ];
    }
    if (isset($_GET['app']) && !is_string($_GET['app'])) return [422, ['code' => 'invalid_app']];
    if (isset($_GET['app'])) {
        $public = array_values(array_filter($public, static fn(array $project): bool => $project['app_key'] === (string)$_GET['app']));
    }
    return [200, $container['project_repo']->withPublicSummaries($public)];
});
