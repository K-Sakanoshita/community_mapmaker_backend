<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

interface UserRepositoryInterface
{
    public function create(string $userid, string $useridNormalized, ?string $email, ?string $emailNormalized, string $passwordHash, string $status, string $role = 'user'): array;
    public function findById(int $id): ?array;
    public function findByIdentity(string $identityNormalized): ?array;
    public function activate(int $id): void;
    public function updatePassword(int $id, string $passwordHash): void;
    public function recordLogin(int $id): void;
}

interface AdminUserRepositoryInterface
{
    public function list(array $filters, int $page, int $perPage): array;
    public function find(int $id): ?array;
    public function update(int $id, string $status, string $role): ?array;
    public function countActiveAdmins(): int;
    public function projectsExist(array $projectIds): bool;
    public function setProjects(int $userId, array $projects): void;
}

interface ProjectAccessRepositoryInterface
{
    public function rolesForUser(int $userId): array;
    public function roleForUser(int $userId, string $appKey): ?string;
}

interface AuditLogRepositoryInterface
{
    public function record(int $adminUserId, string $action, string $targetType, ?int $targetId, array $detail = []): void;
    public function list(int $page, int $perPage): array;
}

interface TokenRepositoryInterface
{
    public function issue(int $userId, string $purpose, string $tokenHash, \DateTimeImmutable $expiresAt): void;
    public function consume(string $purpose, string $tokenHash, \DateTimeImmutable $now): ?array;
    public function invalidate(int $userId, string $purpose): void;
}

interface RateLimiterInterface
{
    public function hit(string $action, string $subject, int $limit, int $windowSeconds): bool;
}

interface TransactionManagerInterface
{
    public function run(callable $callback): mixed;
}

interface Mailer
{
    public function sendVerification(array $user, string $verificationUrl, \DateTimeImmutable $expiresAt): bool;
    public function sendPasswordReset(array $user, string $resetUrl, \DateTimeImmutable $expiresAt): bool;
}

namespace CommunityMapMaker\Activity;

interface ActivityRepositoryInterface
{
    public function list(string $appKey, ?string $osmid = null): array;
    public function find(string $appKey, string $activityKey): ?array;
    /** Include a soft-deleted row when matching an import key. */
    public function findForImport(string $appKey, string $activityKey): ?array;
    public function restoreForImport(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array;
    /** @param array{latitude: ?float, longitude: ?float}|null $coordinates */
    public function create(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $createdByUserId = null, ?array $coordinates = null): array;
    /** Omitted coordinates preserve the snapshot; an explicit null pair clears it.
     * @param array{latitude: ?float, longitude: ?float}|null $coordinates
     */
    public function update(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array;
    public function delete(string $appKey, string $activityKey): bool;
}

interface ProjectRepositoryInterface
{
    public function list(bool $onlyEnabled = false): array;
    public function find(string $appKey): ?array;
    public function create(string $appKey, string $projectName, array $schema, bool $enabled = true): array;
    public function update(string $appKey, ?string $projectName, ?array $schema, ?bool $enabled = null): ?array;
    public function delete(string $appKey): bool;
}
