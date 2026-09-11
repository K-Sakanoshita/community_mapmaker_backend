<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use PDOException;
use RuntimeException;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $userid, string $useridNormalized, ?string $email, ?string $emailNormalized, string $passwordHash, string $status, string $role = 'user'): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO users (userid, userid_normalized, email, email_normalized, password_hash, status, role, email_verified_at, created_at, updated_at) '
            . 'VALUES (:userid, :userid_normalized, :email, :email_normalized, :password_hash, :status, :role, :verified_at, :created_at, :updated_at)'
        );
        try {
            $statement->execute([
                ':userid' => $userid,
                ':userid_normalized' => $useridNormalized,
                ':email' => $email,
                ':email_normalized' => $emailNormalized,
                ':password_hash' => $passwordHash,
                ':status' => $status,
                ':role' => $role,
                ':verified_at' => $status === 'active' && $email !== null ? $now : null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DuplicateIdentityException('User ID or email is already registered.', 0, $error);
            }
            throw $error;
        }
        return $this->findById((int)$this->pdo->lastInsertId())
            ?? throw new RuntimeException('Created user could not be loaded.');
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function findByIdentity(string $identityNormalized): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM users WHERE userid_normalized = :userid_identity OR email_normalized = :email_identity LIMIT 1'
        );
        $statement->execute([
            ':userid_identity' => $identityNormalized,
            ':email_identity' => $identityNormalized,
        ]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function activate(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE users SET status = 'active', email_verified_at = COALESCE(email_verified_at, :verified_at), updated_at = :updated_at WHERE id = :id AND status = 'pending'"
        );
        $now = gmdate('Y-m-d H:i:s');
        $statement->execute([':verified_at' => $now, ':updated_at' => $now, ':id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
        $statement->execute([':password_hash' => $passwordHash, ':updated_at' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
    }

    public function recordLogin(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = :last_login_at WHERE id = :id');
        $statement->execute([':last_login_at' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
    }
}

final class DuplicateIdentityException extends RuntimeException
{
}
