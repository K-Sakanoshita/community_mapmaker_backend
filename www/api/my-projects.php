<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';
use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\{Http, MyProjects, MyProjectsDenied};
$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/MyProjects.php';
$method = Http::requireMethods(['GET', 'DELETE']);
ActivityApi::run(function () use ($container, $method): array {
    if ($method === 'DELETE' && ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') return [403, ['code' => 'console_header_required']];
    $actor = ActivityApi::requireAuthentication($container);
    $service = new MyProjects($container['database']->pdo(), $container['audit_logs']);
    try {
        if ($method === 'GET') return [200, $service->list((int)$actor['id'], (int)($_GET['page'] ?? 1))];
        $input = Http::jsonInput(1024);
        if (array_diff(array_keys($input), ['app']) !== []) return [422, ['code' => 'invalid_input']];
        $appKey = $input['app'] ?? '';
        if (!is_string($appKey) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $appKey)) return [422, ['code' => 'invalid_app']];
        return [200, $service->leave((int)$actor['id'], $appKey)];
    } catch (MyProjectsDenied $error) {
        return [match ($error->getMessage()) { 'authentication_required' => 401, 'project_participation_banned' => 403, default => 409 }, ['code' => $error->getMessage()]];
    }
});
