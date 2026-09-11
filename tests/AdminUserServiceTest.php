<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\AdminUserRepositoryInterface;
use CommunityMapMaker\Auth\AdminUserService;
use CommunityMapMaker\Auth\AuditLogRepositoryInterface;
use CommunityMapMaker\Auth\AuthService;
use CommunityMapMaker\Auth\DuplicateIdentityException;
use CommunityMapMaker\Auth\LastAdminException;
use CommunityMapMaker\Auth\Mailer;
use CommunityMapMaker\Auth\RateLimiterInterface;
use CommunityMapMaker\Auth\TokenRepositoryInterface;
use CommunityMapMaker\Auth\TransactionManagerInterface;
use CommunityMapMaker\Auth\UserRepositoryInterface;
use CommunityMapMaker\Auth\ValidationException;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/UserRepository.php';
require_once dirname(__DIR__) . '/lib/AuthService.php';
require_once dirname(__DIR__) . '/lib/AdminUserService.php';
require_once dirname(__DIR__) . '/lib/RateLimiter.php';

final class AdminMemoryUsers implements UserRepositoryInterface, AdminUserRepositoryInterface
{
    public array $rows = [];
    public array $projects = [];
    private int $nextId = 1;

    public function seed(string $userid, string $email, string $password, string $status, string $role): array
    {
        return $this->create($userid, strtolower($userid), $email, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $status, $role);
    }

    public function create(string $userid, string $useridNormalized, ?string $email, ?string $emailNormalized, string $passwordHash, string $status, string $role = 'user'): array
    {
        foreach ($this->rows as $row) {
            if ($row['userid_normalized'] === $useridNormalized || ($emailNormalized !== null && $row['email_normalized'] === $emailNormalized)) throw new DuplicateIdentityException();
        }
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'id' => $this->nextId++, 'userid' => $userid, 'userid_normalized' => $useridNormalized,
            'email' => $email, 'email_normalized' => $emailNormalized, 'password_hash' => $passwordHash,
            'status' => $status, 'role' => $role, 'email_verified_at' => $status === 'active' && $email !== null ? $now : null,
            'last_login_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
        return $this->rows[$row['id']] = $row;
    }

    public function findById(int $id): ?array { return $this->rows[$id] ?? null; }
    public function findByIdentity(string $identityNormalized): ?array
    {
        foreach ($this->rows as $row) if ($row['userid_normalized'] === $identityNormalized || $row['email_normalized'] === $identityNormalized) return $row;
        return null;
    }
    public function activate(int $id): void
    {
        if (($this->rows[$id]['status'] ?? '') === 'pending') {
            $this->rows[$id]['status'] = 'active'; $this->rows[$id]['email_verified_at'] = gmdate('Y-m-d H:i:s');
        }
    }
    public function updatePassword(int $id, string $passwordHash): void { $this->rows[$id]['password_hash'] = $passwordHash; }
    public function recordLogin(int $id): void { $this->rows[$id]['last_login_at'] = gmdate('Y-m-d H:i:s'); }

    public function list(array $filters, int $page, int $perPage): array
    {
        $items = array_values(array_filter(array_map(fn(array $row): array => $this->safe($row), $this->rows), function (array $row) use ($filters): bool {
            return ($filters['search'] === '' || str_contains(strtolower($row['userid'] . ' ' . $row['email']), $filters['search']))
                && ($filters['status'] === '' || $row['status'] === $filters['status'])
                && ($filters['role'] === '' || $row['role'] === $filters['role']);
        }));
        return ['items' => array_slice($items, ($page - 1) * $perPage, $perPage), 'pagination' => [
            'page' => $page, 'per_page' => $perPage, 'total' => count($items), 'total_pages' => max(1, (int)ceil(count($items) / $perPage)),
        ]];
    }
    public function find(int $id): ?array
    {
        if (!isset($this->rows[$id])) return null;
        return $this->safe($this->rows[$id]) + ['projects' => $this->projects[$id] ?? [], 'recent_activities' => []];
    }
    public function update(int $id, string $status, string $role): ?array
    {
        if (!isset($this->rows[$id])) return null;
        $this->rows[$id]['status'] = $status; $this->rows[$id]['role'] = $role; $this->rows[$id]['updated_at'] = gmdate('Y-m-d H:i:s');
        return $this->find($id);
    }
    public function countActiveAdmins(): int
    {
        return count(array_filter($this->rows, fn(array $row): bool => $row['status'] === 'active' && $row['role'] === 'admin'));
    }
    public function projectsExist(array $projectIds): bool { return count(array_diff($projectIds, [10, 20])) === 0; }
    public function setProjects(int $userId, array $projects): void { $this->projects[$userId] = $projects; }
    private function safe(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'userid', 'email', 'status', 'role', 'email_verified_at', 'last_login_at', 'created_at', 'updated_at']))
            + ['activity_count' => 0, 'project_count' => count($this->projects[$row['id']] ?? [])];
    }
}

final class AdminMemoryTokens implements TokenRepositoryInterface
{
    public array $rows = [];
    public function issue(int $userId, string $purpose, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->invalidate($userId, $purpose); $this->rows[] = compact('userId', 'purpose', 'tokenHash', 'expiresAt') + ['used' => false];
    }
    public function consume(string $purpose, string $tokenHash, DateTimeImmutable $now): ?array
    {
        foreach ($this->rows as &$row) if ($row['purpose'] === $purpose && $row['tokenHash'] === $tokenHash && !$row['used'] && $row['expiresAt'] > $now) { $row['used'] = true; return ['user_id' => $row['userId']]; }
        return null;
    }
    public function invalidate(int $userId, string $purpose): void { foreach ($this->rows as &$row) if ($row['userId'] === $userId && $row['purpose'] === $purpose) $row['used'] = true; }
}

final class AdminAllowLimits implements RateLimiterInterface { public function hit(string $action, string $subject, int $limit, int $windowSeconds): bool { return true; } }
final class AdminTransactions implements TransactionManagerInterface { public function run(callable $callback): mixed { return $callback(); } }
final class AdminRecordingMailer implements Mailer
{
    public array $verification = []; public array $resets = [];
    public function sendVerification(array $user, string $verificationUrl, DateTimeImmutable $expiresAt): bool { $this->verification[] = $verificationUrl; return true; }
    public function sendPasswordReset(array $user, string $resetUrl, DateTimeImmutable $expiresAt): bool { $this->resets[] = $resetUrl; return true; }
}
final class AdminMemoryAudit implements AuditLogRepositoryInterface
{
    public array $rows = [];
    public function record(int $adminUserId, string $action, string $targetType, ?int $targetId, array $detail = []): void { $this->rows[] = compact('adminUserId', 'action', 'targetType', 'targetId', 'detail'); }
    public function list(int $page, int $perPage): array { return ['items' => $this->rows, 'pagination' => []]; }
}

function adminAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function adminThrows(callable $callback, string $class, string $message): void
{
    try { $callback(); } catch (Throwable $error) { adminAssert($error instanceof $class, $message . ': got ' . $error::class); return; }
    throw new RuntimeException($message . ': no exception');
}
function adminToken(string $url): string { parse_str((string)parse_url($url, PHP_URL_QUERY), $query); return (string)$query['token']; }

$users = new AdminMemoryUsers();
$admin = $users->seed('admin', 'admin@example.jp', 'admin password', 'active', 'admin');
$tokens = new AdminMemoryTokens(); $mailer = new AdminRecordingMailer(); $audit = new AdminMemoryAudit(); $transactions = new AdminTransactions();
$config = [
    'password_min_length' => 8, 'verification_token_ttl_seconds' => 3600, 'password_reset_token_ttl_seconds' => 3600,
    'resend_cooldown_seconds' => 1, 'verification_url' => 'https://example.jp/verify', 'password_reset_url' => 'https://example.jp/reset',
    'rate_limits' => [
        'resend_client' => ['limit' => 10, 'window_seconds' => 3600], 'resend_email' => ['limit' => 10, 'window_seconds' => 3600],
        'reset_client' => ['limit' => 10, 'window_seconds' => 3600], 'reset_email' => ['limit' => 10, 'window_seconds' => 3600],
    ],
];
$auth = new AuthService($users, $tokens, new AdminAllowLimits(), $transactions, $mailer, $config);
$service = new AdminUserService($users, $auth, $audit, $transactions);
adminAssert($auth->authenticate('admin', 'admin password')['role'] === 'admin', 'Authentication must return the admin role.');
$invited = $service->create(['userid' => 'new-user', 'email' => 'new@example.jp', 'role' => 'user', 'projects' => [['project_id' => 10, 'role' => 'editor']]], (int)$admin['id'], '127.0.0.1');
adminAssert($invited['status'] === 'pending' && $invited['password_setup_email_sent'] === true, 'Admin invitation must create a pending user and send setup mail.');
adminAssert($invited['creation_mode'] === 'invitation', 'Email-based creation must report invitation mode.');
adminAssert(!array_key_exists('password_hash', $invited), 'Admin responses must not expose password hashes.');
adminAssert($invited['projects'][0]['project_id'] === 10, 'Project assignments must be stored with the invitation.');
$auth->resetPassword(['token' => adminToken($mailer->resets[0]), 'password' => 'new user password', 'password_confirmation' => 'new user password']);
adminAssert($auth->authenticate('new-user', 'new user password') !== null, 'Setting an invited password must activate the account.');
$managed = $service->create([
    'userid' => 'managed-user', 'email' => '', 'password' => 'managed password',
    'password_confirmation' => 'managed password', 'role' => 'user', 'projects' => [['project_id' => 20, 'role' => 'viewer']],
], (int)$admin['id'], '127.0.0.1');
adminAssert($managed['status'] === 'active' && $managed['email'] === null, 'An email-less managed user must be active without a fabricated email address.');
adminAssert($managed['email_verified_at'] === null && $managed['creation_mode'] === 'direct', 'An email-less managed user must not be marked email-verified and must report direct mode.');
adminAssert($managed['password_setup_email_sent'] === false && count($mailer->resets) === 1, 'Direct creation must not send a setup email.');
adminAssert($auth->authenticate('managed-user', 'managed password') !== null, 'A directly created user must authenticate with the administrator-supplied password.');
adminAssert(!array_key_exists('password_hash', $managed) && !array_key_exists('password', $managed), 'Direct creation responses must not expose credentials.');
$passwordUpdated = $service->setPassword((int)$managed['id'], [
    'password' => 'updated managed password', 'password_confirmation' => 'updated managed password',
], (int)$admin['id']);
adminAssert($passwordUpdated['status'] === 'active' && !array_key_exists('password_hash', $passwordUpdated), 'Admin password reset must preserve status and return only safe user data.');
adminAssert($auth->authenticate('managed-user', 'managed password') === null, 'Admin password reset must invalidate the old password.');
adminAssert($auth->authenticate('managed-user', 'updated managed password') !== null, 'Admin password reset must enable the new password.');
adminThrows(fn() => $service->setPassword((int)$managed['id'], [
    'password' => 'another password', 'password_confirmation' => 'different password',
], (int)$admin['id']), ValidationException::class, 'Admin password reset must validate confirmation.');
$managedSecond = $service->create([
    'userid' => 'managed-user-2', 'password' => 'second managed password',
    'password_confirmation' => 'second managed password', 'projects' => [],
], (int)$admin['id'], '127.0.0.1');
adminAssert($managedSecond['email'] === null, 'Multiple users without email addresses must be allowed.');
adminThrows(fn() => $service->create([
    'userid' => 'bad-managed', 'password' => 'managed password', 'password_confirmation' => 'different password', 'projects' => [],
], (int)$admin['id'], '127.0.0.1'), ValidationException::class, 'Direct creation must validate password confirmation.');
adminThrows(fn() => $service->sendPasswordReset((int)$managed['id'], (int)$admin['id'], '127.0.0.1'), ValidationException::class, 'Users without email addresses must not receive password reset mail.');
$updated = $service->update((int)$invited['id'], ['status' => 'disabled', 'role' => 'user'], (int)$admin['id']);
adminAssert($updated['status'] === 'disabled' && $users->projects[$invited['id']][0]['project_id'] === 10, 'Disabling a user must preserve project assignments unless explicitly replaced.');
adminThrows(fn() => $service->update((int)$admin['id'], ['role' => 'user'], (int)$admin['id']), LastAdminException::class, 'The last active admin must not be demoted.');
$pending = $service->create(['userid' => 'pending-user', 'email' => 'pending@example.jp', 'projects' => []], (int)$admin['id'], '127.0.0.1');
$service->resendVerification((int)$pending['id'], (int)$admin['id'], '127.0.0.1');
adminAssert(count($mailer->verification) === 1, 'Pending users must be able to receive verification mail.');
$service->sendPasswordReset((int)$admin['id'], (int)$admin['id'], '127.0.0.1');
adminAssert(count($mailer->resets) === 3, 'Active users must be able to receive admin-triggered password reset mail.');
adminThrows(fn() => $service->sendPasswordReset((int)$invited['id'], (int)$admin['id'], '127.0.0.1'), ValidationException::class, 'Disabled users must not receive password reset mail.');
$auditDetails = json_encode(array_column($audit->rows, 'detail'));
adminAssert(
    count($audit->rows) >= 5
        && !str_contains($auditDetails, 'password_hash')
        && !str_contains($auditDetails, 'token'),
    'Important actions must be audited without password hashes or tokens.'
);

echo "Admin user behavior: ok\n";
