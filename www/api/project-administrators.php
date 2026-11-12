<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\{Http, ProjectAdministrators, ProjectAdministratorConflict, ValidationException};

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/ProjectAdministrators.php';
$method = Http::requireMethods(['GET', 'POST', 'DELETE']);
ActivityApi::run(function () use ($container, $method): array {
    $actor = ActivityApi::requireAuthentication($container);
    $input = $method === 'GET' ? [] : Http::jsonInput(4096);
    try {
        $service = new ProjectAdministrators($container['database']->pdo(), $container['audit_logs']);
        $result = $service->execute((int)$actor['id'], ActivityApi::appKey($input), $method, $input['userid'] ?? null);
        return [200, $result];
    } catch (ValidationException $error) {
        return [422, ['status' => 'error', 'code' => 'validation_failed', 'errors' => $error->errors]];
    } catch (ProjectAdministratorConflict $error) {
        return [409, ['status' => 'error', 'code' => $error->getMessage()]];
    }
});
