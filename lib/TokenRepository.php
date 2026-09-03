<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use DateTimeImmutable;
use PDO;

final class TokenRepository implements TokenRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function issue(int $userId, string $purpose, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->invalidate($userId, $purpose);
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, used_at, created_at) '
            . 'VALUES (:user_id, :purpose, :token_hash, :expires_at, NULL, :created_at)'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':purpose' => $purpose,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function consume(string $purpose, string $tokenHash, DateTimeImmutable $now): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id FROM auth_tokens '
            . 'WHERE purpose = :purpose AND token_hash = :token_hash AND used_at IS NULL AND expires_at > :now '
            . 'LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            ':purpose' => $purpose,
            ':token_hash' => $tokenHash,
            ':now' => $now->format('Y-m-d H:i:s'),
        ]);
        $token = $statement->fetch();
        if ($token === false) return null;

        $update = $this->pdo->prepare('UPDATE auth_tokens SET used_at = :used_at WHERE id = :id AND used_at IS NULL');
        $update->execute([':used_at' => $now->format('Y-m-d H:i:s'), ':id' => $token['id']]);
        return $update->rowCount() === 1 ? $token : null;
    }

    public function invalidate(int $userId, string $purpose): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE auth_tokens SET used_at = COALESCE(used_at, :used_at) WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL'
        );
        $statement->execute([
            ':used_at' => gmdate('Y-m-d H:i:s'),
            ':user_id' => $userId,
            ':purpose' => $purpose,
        ]);
    }
}

