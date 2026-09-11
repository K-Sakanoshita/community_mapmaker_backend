<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\AdminApi;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/AdminApi.php';

Http::requireMethod('GET');

AdminApi::run(function () use ($container): array {
    AdminApi::requireAdmin($container);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    return [200, $container['audit_logs']->list($page, $perPage)];
});
