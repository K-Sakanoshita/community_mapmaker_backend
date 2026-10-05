<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\PortalPreview;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
require_once dirname(__DIR__) . '/lib/PortalPreview.php';
Http::requireMethod('GET');

ActivityApi::run(function () use ($container): array {
    $appKey = trim((string)($_GET['app'] ?? ''));
    foreach ($container['project_service']->list() as $project) {
        if ($project['app_key'] !== $appKey || !$project['frontend_public'] || !$project['frontend_url']) continue;
        $url = (string)$project['frontend_url'];
        $cachePath = sys_get_temp_dir() . '/cmm-portal-' . hash('sha256', $url) . '.json';
        if (is_file($cachePath) && time() - (int)filemtime($cachePath) < 21600) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached) && isset($cached['description']) && array_key_exists('image_url', $cached)) return [200, $cached];
        }
        $preview = PortalPreview::fetch($url);
        @file_put_contents($cachePath, json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return [200, $preview];
    }
    return [404, ['status' => 'error', 'code' => 'project_not_found']];
});
