<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';
use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\{Http, ProjectUsers, ProjectUserConflict, ValidationException};
$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/ProjectUsers.php';
$method = Http::requireMethods(['GET', 'PUT']);
ActivityApi::run(function () use ($container, $method): array {
    if ($method !== 'GET' && ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') return [403, ['code' => 'console_header_required']];
    $actor = ActivityApi::requireAuthentication($container);
    $input = $method === 'GET' ? $_GET : Http::jsonInput(4096);
    try {
        $service = new ProjectUsers($container['database']->pdo(), $container['audit_logs']);
        return [200, $service->execute((int)$actor['id'], ActivityApi::appKey($input), $method, $input)];
    } catch (ValidationException $error) {
        return [422, ['code' => 'validation_failed', 'errors' => $error->errors]];
    } catch (ProjectUserConflict $error) {
        return [409, ['code' => $error->getMessage()]];
    }
});
