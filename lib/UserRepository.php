<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $userid, string $useridNormalized, ?string $email, ?string $emailNormalized, ?string $passwordHash, string $status, string $role = 'user'): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO users (userid, userid_normalized, email, email_normalized, password_hash, status, role, email_verified_at, created_at, updated_at) '
            . 'VALUES (:userid, :userid_normalized, :email, :email_normalized, :password_hash, :status, :role, :verified_at, :created_at, :updated_at)'
        );
        $standalone = !$this->pdo->inTransaction();
        if ($standalone) $this->pdo->beginTransaction();
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
            $id = (int)$this->pdo->lastInsertId();
            $this->assertUseridAvailable($useridNormalized, $id);
            $user = $this->findById($id) ?? throw new RuntimeException('Created user could not be loaded.');
            if ($standalone) $this->pdo->commit();
            return $user;
        } catch (Throwable $error) {
            if ($standalone && $this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
                throw new DuplicateIdentityException('User ID or email is already registered.', 0, $error);
            }
            throw $error;
        }
    }

    /** Called inside a transaction so the rename and old public alias take effect together. */
    public function renameUserid(array $account, string $userid, string $normalized): ?array
    {
        $id = (int)$account['id'];
        $update = $this->pdo->prepare(
            "UPDATE users SET userid = :userid, userid_normalized = :normalized, updated_at = :when
             WHERE id = :id AND status = 'active' AND role = :role AND updated_at = :previous_update
               AND BINARY userid = :previous_userid AND BINARY password_hash <=> :password_hash"
        );
        try {
            $update->execute([
                ':userid' => $userid, ':normalized' => $normalized, ':when' => gmdate('Y-m-d H:i:s'),
                ':id' => $id, ':role' => $account['role'], ':previous_update' => $account['updated_at'],
                ':previous_userid' => $account['userid'], ':password_hash' => $account['password_hash'],
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DuplicateIdentityException('User ID is already registered.', 0, $error);
            }
            throw $error;
        }
        if ($update->rowCount() !== 1) return null;
        $this->assertUseridAvailable($normalized, $id);
        if ($normalized !== (string)$account['userid_normalized']) {
            $alias = $this->pdo->prepare(
                'INSERT INTO userid_aliases (userid_normalized, user_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE user_id = user_id'
            );
            $alias->execute([$account['userid_normalized'], $id]);
        }
        return $this->findById($id);
    }

    private function assertUseridAvailable(string $normalized, int $userId): void
    {
        $query = $this->pdo->prepare('SELECT user_id FROM userid_aliases WHERE userid_normalized = ? FOR UPDATE');
        $query->execute([$normalized]);
        $aliasOwner = $query->fetchColumn();
        if ($aliasOwner !== false && (int)$aliasOwner !== $userId) {
            throw new DuplicateIdentityException('User ID is reserved for another account.');
        }
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

    public function activeAdminEmails(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT email FROM users WHERE role = 'admin' AND status = 'active' AND email_verified_at IS NOT NULL AND email IS NOT NULL AND email <> ''"
        );
        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN),
            static fn(mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    public function addEmailIfMissing(int $id, string $email, string $emailNormalized): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET email = :email, email_normalized = :email_normalized, email_verified_at = NULL, updated_at = :updated_at '
            . 'WHERE id = :id AND email IS NULL'
        );
        try {
            $statement->execute([
                ':email' => $email,
                ':email_normalized' => $emailNormalized,
                ':updated_at' => gmdate('Y-m-d H:i:s'),
                ':id' => $id,
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DuplicateIdentityException('Email is already registered.', 0, $error);
            }
            throw $error;
        }
        return $statement->rowCount() === 1;
    }

    public function verifyEmail(int $id): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            "UPDATE users SET status = CASE WHEN status = 'pending' THEN 'active' ELSE status END, "
            . 'email_verified_at = :verified_at, updated_at = :updated_at WHERE id = :id AND email IS NOT NULL'
        );
        $statement->execute([':verified_at' => $now, ':updated_at' => $now, ':id' => $id]);
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

    /** Called inside the transaction that revokes pending password resets. */
    public function updateConsolePassword(array $account, string $passwordHash): ?array
    {
        $statement = $this->pdo->prepare(
            "UPDATE users SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id AND status = 'active' AND role = :role AND updated_at = :previous_update
               AND BINARY password_hash <=> :previous_hash"
        );
        $statement->execute([
            ':password_hash' => $passwordHash, ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $account['id'], ':role' => $account['role'], ':previous_update' => $account['updated_at'],
            ':previous_hash' => $account['password_hash'],
        ]);
        return $statement->rowCount() === 1 ? $this->findById((int)$account['id']) : null;
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
