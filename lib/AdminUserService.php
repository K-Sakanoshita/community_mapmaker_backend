<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use RuntimeException;

final class AdminUserService
{
    private const STATUSES = ['pending', 'active', 'disabled'];
    private const ROLES = ['user', 'admin'];
    private const PROJECT_ROLES = ['viewer', 'editor', 'project_admin'];

    public function __construct(
        private AdminUserRepositoryInterface $users,
        private AuthService $auth,
        private AuditLogRepositoryInterface $audit,
        private TransactionManagerInterface $transactions
    ) {
    }

    public function list(array $input): array
    {
        $page = max(1, (int)($input['page'] ?? 1));
        $perPage = min(100, max(1, (int)($input['per_page'] ?? 25)));
        $search = mb_strtolower(trim((string)($input['search'] ?? '')), 'UTF-8');
        if (mb_strlen($search, 'UTF-8') > 255) throw new ValidationException(['search' => 'Search must not exceed 255 characters.']);
        $status = trim((string)($input['status'] ?? ''));
        $role = trim((string)($input['role'] ?? ''));
        $verified = trim((string)($input['verified'] ?? ''));
        if ($status !== '' && !in_array($status, self::STATUSES, true)) throw new ValidationException(['status' => 'Invalid status filter.']);
        if ($role !== '' && !in_array($role, self::ROLES, true)) throw new ValidationException(['role' => 'Invalid role filter.']);
        if ($verified !== '' && !in_array($verified, ['yes', 'no'], true)) throw new ValidationException(['verified' => 'Verified filter must be yes or no.']);
        $projectId = (int)($input['project_id'] ?? 0);
        if ($projectId < 0) throw new ValidationException(['project_id' => 'Project ID must be positive.']);
        return $this->users->list([
            'search' => $search,
            'status' => $status,
            'role' => $role,
            'verified' => $verified,
            'project_id' => $projectId,
        ], $page, $perPage);
    }

    public function find(int $id): array
    {
        if ($id < 1) throw new ValidationException(['id' => 'User ID must be positive.']);
        return $this->users->find($id) ?? throw new AdminUserNotFoundException('User not found.');
    }

    public function create(array $input, int $adminUserId, string $clientIdentifier): array
    {
        $projects = $this->projects($input['projects'] ?? []);
        $hasEmail = trim((string)($input['email'] ?? '')) !== '';
        $created = $hasEmail
            ? $this->auth->inviteUser($input, $clientIdentifier)
            : $this->auth->createManagedUserWithoutEmail($input);
        $userId = (int)$created['user_id'];
        $creationMode = $hasEmail ? 'invitation' : 'direct';
        $this->transactions->run(function () use ($userId, $projects, $adminUserId, $input, $creationMode): void {
            $this->users->setProjects($userId, $projects);
            $this->audit->record($adminUserId, 'user.create', 'user', $userId, [
                'role' => (string)($input['role'] ?? 'user'),
                'creation_mode' => $creationMode,
                'project_ids' => array_column($projects, 'project_id'),
            ]);
        });
        return $this->find($userId) + [
            'creation_mode' => $creationMode,
            'password_setup_email_sent' => (bool)($created['password_setup_email_sent'] ?? false),
        ];
    }

    public function update(int $id, array $input, int $adminUserId): array
    {
        $current = $this->find($id);
        $status = array_key_exists('status', $input) ? trim((string)$input['status']) : $current['status'];
        $role = array_key_exists('role', $input) ? trim((string)$input['role']) : $current['role'];
        if (!in_array($status, self::STATUSES, true)) throw new ValidationException(['status' => 'Status must be pending, active, or disabled.']);
        if (!in_array($role, self::ROLES, true)) throw new ValidationException(['role' => 'Role must be user or admin.']);
        $wasActiveAdmin = $current['status'] === 'active' && $current['role'] === 'admin';
        $willBeActiveAdmin = $status === 'active' && $role === 'admin';
        $projects = array_key_exists('projects', $input) ? $this->projects($input['projects']) : null;
        $this->transactions->run(function () use ($id, $status, $role, $projects, $adminUserId, $current, $wasActiveAdmin, $willBeActiveAdmin): void {
            if ($wasActiveAdmin && !$willBeActiveAdmin && $this->users->countActiveAdmins() <= 1) {
                throw new LastAdminException('The last active admin cannot be disabled or demoted.');
            }
            if ($this->users->update($id, $status, $role) === null) throw new AdminUserNotFoundException('User not found.');
            if ($projects !== null) $this->users->setProjects($id, $projects);
            $this->audit->record($adminUserId, 'user.update', 'user', $id, [
                'status' => ['from' => $current['status'], 'to' => $status],
                'role' => ['from' => $current['role'], 'to' => $role],
                'project_ids' => $projects === null ? null : array_column($projects, 'project_id'),
            ]);
        });
        return $this->find($id);
    }

    public function resendVerification(int $id, int $adminUserId, string $clientIdentifier): array
    {
        $this->find($id);
        $result = $this->auth->resendVerificationForUser($id, $clientIdentifier);
        $this->audit->record($adminUserId, 'user.verification_resend', 'user', $id, [
            'email_sent' => (bool)$result['verification_email_sent'],
        ]);
        return $result;
    }

    public function sendPasswordReset(int $id, int $adminUserId, string $clientIdentifier): array
    {
        $this->find($id);
        $result = $this->auth->sendPasswordResetForUser($id, $clientIdentifier);
        $this->audit->record($adminUserId, 'user.password_reset_send', 'user', $id, [
            'email_sent' => (bool)$result['reset_email_sent'],
        ]);
        return $result;
    }

    public function setPassword(int $id, array $input, int $adminUserId): array
    {
        $this->find($id);
        $this->auth->setPasswordForUser($id, $input);
        $this->audit->record($adminUserId, 'user.password_set', 'user', $id);
        return $this->find($id);
    }

    private function projects(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new ValidationException(['projects' => 'Projects must be an array.']);
        $projects = [];
        foreach ($value as $index => $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $projectId = (int)$item;
                $role = 'editor';
            } elseif (is_array($item)) {
                $projectId = (int)($item['project_id'] ?? 0);
                $role = trim((string)($item['role'] ?? 'editor'));
            } else {
                throw new ValidationException(['projects.' . $index => 'Project assignment is invalid.']);
            }
            if ($projectId < 1) throw new ValidationException(['projects.' . $index . '.project_id' => 'Project ID must be positive.']);
            if (!in_array($role, self::PROJECT_ROLES, true)) throw new ValidationException(['projects.' . $index . '.role' => 'Invalid project role.']);
            if (isset($projects[$projectId])) throw new ValidationException(['projects.' . $index => 'Project is duplicated.']);
            $projects[$projectId] = ['project_id' => $projectId, 'role' => $role];
        }
        if (!$this->users->projectsExist(array_keys($projects))) {
            throw new ValidationException(['projects' => 'One or more projects do not exist.']);
        }
        return array_values($projects);
    }
}

final class AdminUserNotFoundException extends RuntimeException
{
}

final class LastAdminException extends RuntimeException
{
}
