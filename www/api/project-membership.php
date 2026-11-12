<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Auth\{Http, ConsoleSession, ProjectMembership, ProjectMembershipDenied, MyProjects, MyProjectsDenied};

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
require_once CMM_PRIVATE_ROOT . '/lib/ProjectMembership.php';
require_once CMM_PRIVATE_ROOT . '/lib/MyProjects.php';
$method = Http::requireMethods(['GET', 'POST']);
ActivityApi::run(function () use ($container, $method): array {
    if ($method === 'POST' && ($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') return [403, ['code' => 'console_header_required']];
    $input = $method === 'POST' ? Http::jsonInput(1024) : $_GET;
    $appKey = $input['app'] ?? '';
    if (!is_string($appKey) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $appKey)) return [422, ['code' => 'invalid_app']];
    $membership = new ProjectMembership($container['database']->pdo(), $container['audit_logs']);
    try {
        if ($method === 'GET') {
            $user = ConsoleSession::user($container);
            $status = $membership->status($appKey, $user);
            $leave = $status['is_member'] && $user !== null
                ? (new MyProjects($container['database']->pdo(), $container['audit_logs']))->leaveStatus((int)$user['id'], $appKey)
                : ['can_leave' => false, 'leave_reason' => null];
            return [200, $status + $leave];
        }
        $actor = ActivityApi::requireAuthentication($container);
        return [200, $membership->join($appKey, (int)$actor['id'])];
    } catch (ProjectMembershipDenied | MyProjectsDenied $error) {
        return [match ($error->getMessage()) { 'authentication_required' => 401, 'project_participation_banned' => 403, default => 409 }, ['code' => $error->getMessage()]];
    }
});
