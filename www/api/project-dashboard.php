<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_runtime.php';
use CommunityMapMaker\Activity\{ActivityApi, ProjectDashboard};
use CommunityMapMaker\Auth\Http;
$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/ProjectDashboard.php';
Http::requireMethods(['GET']);
header('Cache-Control: private, no-store');
ActivityApi::run(function () use ($container): array {
    $actor = ActivityApi::requireAuthentication($container);
    $app = $_GET['app'] ?? '';
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_string($app) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $app) || $page === false) return [422, ['code' => 'invalid_input']];
    $result = (new ProjectDashboard($container['database']->pdo()))->read($actor, $app, $page);
    // Read field definitions only after the dashboard membership check succeeds.
    return [200, $result + ['fields' => $container['activity_schema']->get($app)['fields']]];
});
