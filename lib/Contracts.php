<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

interface UserRepositoryInterface
{
    public function create(string $userid, string $useridNormalized, string $email, string $emailNormalized, string $passwordHash, string $status): array;
    public function findById(int $id): ?array;
    public function findByIdentity(string $identityNormalized): ?array;
    public function activate(int $id): void;
    public function updatePassword(int $id, string $passwordHash): void;
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

