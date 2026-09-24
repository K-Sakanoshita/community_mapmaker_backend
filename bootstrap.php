<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\AuthService;
use CommunityMapMaker\Auth\AdminUserRepository;
use CommunityMapMaker\Auth\AdminUserService;
use CommunityMapMaker\Auth\AuditLogRepository;
use CommunityMapMaker\Auth\Database;
use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\NativeMailer;
use CommunityMapMaker\Auth\ProjectAccessService;
use CommunityMapMaker\Auth\RateLimiter;
use CommunityMapMaker\Auth\TokenRepository;
use CommunityMapMaker\Auth\UserRepository;
use CommunityMapMaker\Activity\ActivityRepository;
use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivityService;
use CommunityMapMaker\Activity\CsvActivityImport;
use CommunityMapMaker\Activity\ProjectRepository;
use CommunityMapMaker\Activity\ProjectService;

require_once __DIR__ . '/lib/Contracts.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/UserRepository.php';
require_once __DIR__ . '/lib/AdminUserRepository.php';
require_once __DIR__ . '/lib/AuditLogRepository.php';
require_once __DIR__ . '/lib/TokenRepository.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/NativeMailer.php';
require_once __DIR__ . '/lib/AuthService.php';
require_once __DIR__ . '/lib/ConsoleSession.php';
require_once __DIR__ . '/lib/AdminUserService.php';
require_once __DIR__ . '/lib/ProjectAccessService.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/ActivityRepository.php';
require_once __DIR__ . '/lib/ProjectRepository.php';
require_once __DIR__ . '/lib/ActivitySchema.php';
require_once __DIR__ . '/lib/ActivityService.php';
require_once __DIR__ . '/lib/ProjectService.php';
require_once __DIR__ . '/lib/CsvActivityImport.php';

try {
    $configFile = getenv('CMM_AUTH_CONFIG') ?: __DIR__ . '/config/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Backend config is missing. Copy config/config.example.php to config/config.php.');
    }
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Backend config must return an array.');

    $authConfig = (array)($config['auth'] ?? []);
    Http::prepare($authConfig);
    $database = new Database((array)($config['db'] ?? []));
    $userRepo = new UserRepository($database->pdo());
    $authService = new AuthService(
        $userRepo,
        new TokenRepository($database->pdo()),
        new RateLimiter($database->pdo(), (string)($authConfig['rate_limit_secret'] ?? '')),
        $database,
        new NativeMailer((array)($config['mail'] ?? [])),
        $authConfig
    );

    $activityConfig = (array)($config['activity'] ?? []);
    $projectRepo = new ProjectRepository($database->pdo());
    $activitySchema = new ActivitySchema((array)($activityConfig['apps'] ?? []), $projectRepo);
    $activityRepo = new ActivityRepository($database->pdo());
    $activityService = new ActivityService(
        $activityRepo,
        $activitySchema,
        $database
    );
    $projectService = new ProjectService(
        $projectRepo,
        $activitySchema
    );
    $adminUserRepo = new AdminUserRepository($database->pdo());
    $auditLogs = new AuditLogRepository($database->pdo());
    $adminUsers = new AdminUserService($adminUserRepo, $authService, $auditLogs, $database);
    $projectAccess = new ProjectAccessService($adminUserRepo);

    return [
        'auth' => $authService,
        'user_repo' => $userRepo,
        'auth_config' => $authConfig,
        'admin_users' => $adminUsers,
        'project_access' => $projectAccess,
        'audit_logs' => $auditLogs,
        'activity' => $activityService,
        'activity_schema' => $activitySchema,
        'activity_config' => $activityConfig,
        'project_repo' => $projectRepo,
        'project_service' => $projectService,
        'csv_activity_import' => new CsvActivityImport($activitySchema),
    ];
} catch (Throwable $error) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $requestId = bin2hex(random_bytes(8));
    error_log(sprintf('Backend bootstrap error [%s]: %s', $requestId, $error->getMessage()));
    Http::respond(500, ['status' => 'error', 'code' => 'server_error', 'request_id' => $requestId]);
}
