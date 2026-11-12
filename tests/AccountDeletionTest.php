<?php
declare(strict_types=1);

// Explicitly isolated local MariaDB database; never run against production.
if (getenv('CMM_ISOLATED_ACCOUNT_TEST') !== '1') {
    throw new RuntimeException('Run in the local web container with CMM_ISOLATED_ACCOUNT_TEST=1.');
}
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/UserRepository.php';
require_once dirname(__DIR__) . '/lib/AuthService.php';
require_once dirname(__DIR__) . '/lib/RateLimiter.php';
require_once dirname(__DIR__) . '/lib/AccountDeletion.php';
require_once dirname(__DIR__) . '/lib/RegistrationProjects.php';
require_once dirname(__DIR__) . '/lib/ConsoleSession.php';

use CommunityMapMaker\Auth\AccountDeletion;
use CommunityMapMaker\Auth\AccountCredentialsException;
use CommunityMapMaker\Auth\LastAdministratorException;
use CommunityMapMaker\Auth\RateLimiter;
use CommunityMapMaker\Auth\RateLimitExceededException;
use CommunityMapMaker\Auth\RegistrationProjects;
use CommunityMapMaker\Auth\UserRepository;
use CommunityMapMaker\Auth\ValidationException;
use CommunityMapMaker\Auth\ConsoleSession;

$root = new PDO('mysql:host=database;charset=utf8mb4', 'root', 'cmm_local_root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'cmm_account_test_' . bin2hex(random_bytes(6));
$root->exec("CREATE DATABASE `$db`");
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$throws = static function (callable $fn, string $class) use ($check): void {
    try { $fn(); } catch (Throwable $error) { $check($error instanceof $class, 'Expected ' . $class . ', got ' . get_class($error)); return; }
    throw new RuntimeException('Expected ' . $class);
};
try {
    $pdo = new PDO("mysql:host=database;dbname=$db;charset=utf8mb4", 'root', 'cmm_local_root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
    $users = new UserRepository($pdo);
    $hash = password_hash('DisposablePass123!', PASSWORD_DEFAULT);
    $user = $users->create('testuser', 'testuser', 'test@example.test', 'test@example.test', $hash, 'active', 'user');
    $admin = $users->create('testadmin', 'testadmin', 'admin@example.test', 'admin@example.test', $hash, 'active', 'admin');
    $secret = 'isolated-test-secret-at-least-thirty-two-characters';
    $service = new AccountDeletion($pdo, new RateLimiter($pdo, $secret), $secret);
    $input = ['identity' => 'testuser', 'password' => 'DisposablePass123!', 'confirm_userid' => 'testuser'];
    $pdo->exec("INSERT INTO activities (app_key,activity_key,osmid,data_json,created_by_user_id,updated_by_user_id,created_at,updated_at) VALUES ('map','a','node/1','{\"body\":\"keep\"}',{$user['id']},{$user['id']},UTC_TIMESTAMP(),UTC_TIMESTAMP()), ('map','b','way/2','{}',{$admin['id']},{$admin['id']},UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO projects (app_key,project_name,schema_json,created_by_user_id,created_at,updated_at) VALUES ('map','Map','{}',{$user['id']},UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO user_projects (user_id,project_id,role,created_at) VALUES ({$user['id']},1,'contributor',UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO auth_tokens (user_id,purpose,token_hash,expires_at,created_at) VALUES ({$user['id']},'verify_email','" . str_repeat('a', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO admin_audit_logs (admin_user_id,action,target_type,target_id,created_at) VALUES ({$admin['id']},'user.create','user',{$user['id']},UTC_TIMESTAMP()), ({$admin['id']},'project.create','project',1,UTC_TIMESTAMP())");
    $before = $pdo->query("SELECT * FROM activities WHERE activity_key='a'")->fetch();
    $throws(fn() => $service->delete(array_replace($input, ['password' => 'wrong']), 'wrong-pass'), AccountCredentialsException::class);
    $throws(fn() => $service->delete(array_replace($input, ['confirm_userid' => 'testadmin']), 'wrong-confirm'), ValidationException::class);
    $throws(fn() => $service->delete(['identity' => 'missing', 'password' => 'wrong', 'confirm_userid' => 'missing'], 'unknown'), AccountCredentialsException::class);
    $check($users->findById((int)$user['id']) !== null, 'Rejected requests must preserve account');
    $throws(fn() => $service->delete(['identity' => 'testadmin', 'password' => 'DisposablePass123!', 'confirm_userid' => 'testadmin'], 'last-admin'), LastAdministratorException::class);
    $check($users->findById((int)$admin['id']) !== null, 'Last administrator must remain');
    $pdo->exec("CREATE TRIGGER block_account_delete BEFORE DELETE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'");
    $throws(fn() => $service->delete($input, 'rollback'), PDOException::class);
    $check((int)$pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='user'")->fetchColumn() === 1, 'Rollback must restore audit records');
    $check($users->findById((int)$user['id']) !== null, 'Rollback must preserve account');
    $pdo->exec('DROP TRIGGER block_account_delete');
    $service->delete(array_replace($input, ['identity' => ' TEST@EXAMPLE.TEST ', 'user_id' => $admin['id']]), 'delete');
    $check($users->findById((int)$user['id']) === null, 'Account must be physically deleted');
    $after = $pdo->query("SELECT * FROM activities WHERE activity_key='a'")->fetch();
    foreach ($before as $key => $value) {
        $check($after[$key] === (in_array($key, ['created_by_user_id', 'updated_by_user_id'], true) ? null : $value), 'Posted data must be unchanged: ' . $key);
    }
    $check((int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn() === 2, 'All posted data must remain');
    $check($pdo->query("SELECT created_by_user_id FROM projects WHERE app_key='map'")->fetch()['created_by_user_id'] === null, 'Project ownership must detach');
    foreach (['auth_tokens', 'user_projects'] as $table) $check((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0, 'Tokens and memberships must be deleted');
    $check((int)$pdo->query('SELECT COUNT(*) FROM admin_audit_logs')->fetchColumn() === 1, 'Account-related audits only must be removed');
    $check($users->findById((int)$admin['id']) !== null, 'Payload target ID must not delete another user');
    $check((int)$pdo->query("SELECT COUNT(*) FROM auth_rate_limits WHERE action_key='delete_account_client'")->fetchColumn() > 0, 'Client rate limits must remain');
    $check((int)$pdo->query("SELECT COUNT(*) FROM auth_rate_limits WHERE action_key='delete_account_identity' AND subject_hash='" . hash_hmac('sha256', 'identity:testuser', $secret) . "'")->fetchColumn() === 0, 'Identity rate limit must be removed');
    $pending = $users->create('pending', 'pending', 'pending@example.test', 'pending@example.test', $hash, 'pending', 'user');
    $service->delete(['identity' => 'pending', 'password' => 'DisposablePass123!', 'confirm_userid' => 'pending'], 'pending');
    $check($users->findById((int)$pending['id']) === null, 'Unverified accounts must be deletable');
    $disabled = $users->create('disabled', 'disabled', null, null, $hash, 'disabled', 'user');
    $service->delete(['identity' => 'disabled', 'password' => 'DisposablePass123!', 'confirm_userid' => 'disabled'], 'disabled');
    $check($users->findById((int)$disabled['id']) === null, 'Disabled accounts must be deletable');
    $_SERVER['SCRIPT_NAME'] = '/api/console-session.php';
    $_SERVER['HTTP_X_CONSOLE_SESSION'] = '1';
    ConsoleSession::establish($admin);
    $_COOKIE['cmm_console'] = session_id();
    $check(ConsoleSession::user(['user_repo' => $users]) !== null, 'Admin session before deletion');
    $secondAdmin = $users->create('secondadmin', 'secondadmin', null, null, $hash, 'active', 'admin');
    $service->delete(['identity' => 'testadmin', 'password' => 'DisposablePass123!', 'confirm_userid' => 'testadmin'], 'admin-delete');
    $check($users->findById((int)$admin['id']) === null && $users->findById((int)$secondAdmin['id']) !== null, 'Admin may delete when another active admin remains');
    $check(ConsoleSession::user(['user_repo' => $users]) === null, 'Deleting account must revoke existing console session');
    ConsoleSession::destroy();
    for ($i = 0; $i < 5; $i++) $throws(fn() => $service->delete(['identity' => 'missing-2', 'password' => 'wrong', 'confirm_userid' => 'missing-2'], 'limit'), AccountCredentialsException::class);
    $throws(fn() => $service->delete(['identity' => 'missing-2', 'password' => 'wrong', 'confirm_userid' => 'missing-2'], 'limit'), RateLimitExceededException::class);
    $project = ['app_key' => 'map', 'project_name' => 'Map', 'enabled' => true, 'frontend_public' => true];
    foreach (['javascript:alert(1)', 'https://user:pass@example.test/', '//evil.test/', "https://example.test/\n", 'https://example.test\\@evil.test/'] as $unsafe) {
        $check(RegistrationProjects::present($project + ['frontend_url' => $unsafe])['frontend_url'] === null, 'Unsafe project URL must not be published');
    }
    $check(RegistrationProjects::present($project + ['frontend_url' => 'https://example.test/map'])['frontend_url'] === 'https://example.test/map', 'Safe managed URL');
    $check(RegistrationProjects::present(array_replace($project, ['frontend_public' => false])) === null, 'Private project must not be exposed');
    echo "Account deletion: credentials, confirmation, last admin, rollback, FK retention, rate limits and safe URLs passed.\n";
} finally {
    $root->exec("DROP DATABASE `$db`");
}
