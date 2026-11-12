<?php
declare(strict_types=1);
if (getenv('CMM_ISOLATED_PROJECT_ADMINS_TEST') !== '1') throw new RuntimeException('Run in local web container with CMM_ISOLATED_PROJECT_ADMINS_TEST=1.');
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/AuthService.php';
require_once dirname(__DIR__) . '/lib/ProjectAccessService.php';
require_once dirname(__DIR__) . '/lib/ProjectService.php';
require_once dirname(__DIR__) . '/lib/AuditLogRepository.php';
require_once dirname(__DIR__) . '/lib/ProjectAdministrators.php';
use CommunityMapMaker\Auth\{AuditLogRepository, ProjectAdministrators, ProjectAdministratorConflict, ProjectAccessDeniedException, ValidationException};
use CommunityMapMaker\Activity\ProjectNotFoundException;
$root = new PDO('mysql:host=database;charset=utf8mb4', 'root', 'cmm_local_root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'cmm_project_admins_' . bin2hex(random_bytes(6));
$root->exec("CREATE DATABASE `$db`");
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $fn, string $class) use ($check): void {
    try { $fn(); } catch (Throwable $error) { if ($error instanceof $class) return; throw $error; }
    $check(false, 'Expected rejection: ' . $class);
};
try {
    $pdo = new PDO("mysql:host=database;dbname=$db;charset=utf8mb4", 'root', 'cmm_local_root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
    $ids = [];
    $insert = $pdo->prepare('INSERT INTO users (userid,userid_normalized,password_hash,status,role,created_at,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    foreach (['owner' => ['active','user'], 'coadmin' => ['active','user'], 'third' => ['active','user'], 'outsider' => ['active','user'], 'editor' => ['active','user'], 'globaladmin' => ['active','admin'], 'contributor' => ['active','contributor'], 'pending' => ['pending','user'], 'disabled' => ['disabled','user']] as $name => [$status,$role]) {
        $insert->execute([$name,$name,'not-used-by-service-test',$status,$role]);
        $ids[$name] = (int)$pdo->lastInsertId();
    }
    $insert = $pdo->prepare("INSERT INTO projects (app_key,project_name,schema_json,created_by_user_id,created_at,updated_at) VALUES (?,?,'{}',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $projects = [];
    foreach (['owned' => $ids['owner'], 'other' => $ids['outsider'], 'legacy' => null] as $app => $owner) {
        $insert->execute([$app,$app,$owner]); $projects[$app] = (int)$pdo->lastInsertId();
    }
    $member = $pdo->prepare('INSERT INTO user_projects (user_id,project_id,role,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');
    $member->execute([$ids['owner'],$projects['owned'],'project_admin']);
    $member->execute([$ids['editor'],$projects['owned'],'editor']);
    $member->execute([$ids['coadmin'],$projects['legacy'],'project_admin']);
    $member->execute([$ids['outsider'],$projects['other'],'project_admin']);
    $service = new ProjectAdministrators($pdo, new AuditLogRepository($pdo));
    $result = $service->execute($ids['owner'],'owned','GET');
    $check(count($result['administrators']) === 1 && $result['administrators'][0]['is_creator'], 'Creator must be listed');
    foreach (['outsider','editor','contributor','disabled','pending'] as $name) $reject(fn() => $service->execute($ids[$name],'owned','GET'), ProjectAccessDeniedException::class);
    foreach (['pending','disabled','contributor','missing'] as $target) $reject(fn() => $service->execute($ids['owner'],'owned','POST',$target), ValidationException::class);
    $result = $service->execute($ids['owner'],'owned','POST',' COADMIN ');
    $check(count($result['administrators']) === 2, 'Multiple administrators must coexist');
    $service->execute($ids['owner'],'owned','POST','coadmin');
    $check((int)$pdo->query("SELECT COUNT(*) FROM user_projects WHERE project_id={$projects['owned']} AND role='project_admin'")->fetchColumn() === 2, 'Repeated add must be idempotent');
    $service->execute($ids['coadmin'],'owned','POST','third');
    $result = $service->execute($ids['third'],'owned','GET');
    $check(count($result['administrators']) === 3, 'Added administrator can manage administrators');
    $check($pdo->query("SELECT role FROM users WHERE id={$ids['coadmin']}")->fetchColumn() === 'user', 'Project delegation must never elevate global role');
    $reject(fn() => $service->execute($ids['coadmin'],'other','POST','third'), ProjectAccessDeniedException::class);
    $reject(fn() => $service->execute($ids['coadmin'],'owned','DELETE','owner'), ProjectAdministratorConflict::class);
    $reject(fn() => $service->execute($ids['globaladmin'],'legacy','DELETE','coadmin'), ProjectAdministratorConflict::class);
    $service->execute($ids['globaladmin'],'other','POST','coadmin');
    $service->execute($ids['owner'],'owned','DELETE','coadmin');
    $reject(fn() => $service->execute($ids['coadmin'],'owned','GET'), ProjectAccessDeniedException::class);
    $check(count($service->execute($ids['coadmin'],'other','GET')['administrators']) === 2, 'Removing from one project must preserve other assignments');
    $role = $pdo->query("SELECT role FROM user_projects WHERE user_id={$ids['coadmin']} AND project_id={$projects['owned']}")->fetchColumn();
    $check($role === 'editor', 'Removal retains activity editing as editor');
    $service->execute($ids['owner'],'owned','POST','editor');
    $check(count($service->execute($ids['owner'],'owned','GET')['administrators']) === 3, 'Existing editor can become project administrator');
    $pdo->exec("UPDATE users SET status='disabled' WHERE id={$ids['third']}");
    $reject(fn() => $service->execute($ids['third'],'owned','GET'), ProjectAccessDeniedException::class);
    $result = $service->execute($ids['owner'],'owned','GET');
    $third = array_values(array_filter($result['administrators'], fn($u) => $u['id'] === $ids['third']))[0];
    $check(!$third['can_manage'], 'Disabled administrator must be marked unavailable');
    foreach ($result['administrators'] as $row) $check(!array_key_exists('email',$row) && !array_key_exists('password_hash',$row), 'Private user data must not be exposed');
    $pdo->exec("CREATE TRIGGER block_admin_audit BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rollback test'");
    $reject(fn() => $service->execute($ids['owner'],'owned','POST','outsider'), PDOException::class);
    $check((int)$pdo->query("SELECT COUNT(*) FROM user_projects WHERE project_id={$projects['owned']} AND user_id={$ids['outsider']}")->fetchColumn() === 0, 'Audit failure must roll back membership changes');
    $pdo->exec('DROP TRIGGER block_admin_audit');
    $pdo->exec("UPDATE projects SET is_deleted=1 WHERE app_key='owned'");
    $reject(fn() => $service->execute($ids['owner'],'owned','GET'), ProjectNotFoundException::class);
    $check((int)$pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action LIKE 'project.admin.%'")->fetchColumn() >= 5, 'Changes must be audited');
    echo "Project administrators: multiple admins, scoped delegation, role isolation, creator/last-admin protection, immediate revocation and rollback passed.\n";
} finally { $root->exec("DROP DATABASE IF EXISTS `$db`"); }
