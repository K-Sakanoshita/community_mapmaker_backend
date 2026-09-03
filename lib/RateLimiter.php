<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use RuntimeException;

final class RateLimiter implements RateLimiterInterface
{
    public function __construct(private PDO $pdo, private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('auth.rate_limit_secret must contain at least 32 characters.');
        }
    }

    public function hit(string $action, string $subject, int $limit, int $windowSeconds): bool
    {
        $subjectHash = hash_hmac('sha256', $subject, $this->secret);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $windowStart = $now->modify(sprintf('-%d seconds', $windowSeconds));

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT id, window_started_at, attempts FROM auth_rate_limits '
                . 'WHERE action_key = :action_key AND subject_hash = :subject_hash LIMIT 1 FOR UPDATE'
            );
            $select->execute([':action_key' => $action, ':subject_hash' => $subjectHash]);
            $row = $select->fetch();

            if ($row === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO auth_rate_limits (action_key, subject_hash, window_started_at, attempts, updated_at) '
                    . 'VALUES (:action_key, :subject_hash, :window_started_at, 1, :updated_at)'
                );
                $insert->execute([
                    ':action_key' => $action,
                    ':subject_hash' => $subjectHash,
                    ':window_started_at' => $now->format('Y-m-d H:i:s'),
                    ':updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
                $allowed = true;
            } elseif (new \DateTimeImmutable((string)$row['window_started_at'], new \DateTimeZone('UTC')) <= $windowStart) {
                $reset = $this->pdo->prepare(
                    'UPDATE auth_rate_limits SET window_started_at = :window_started_at, attempts = 1, updated_at = :updated_at WHERE id = :id'
                );
                $reset->execute([
                    ':window_started_at' => $now->format('Y-m-d H:i:s'),
                    ':updated_at' => $now->format('Y-m-d H:i:s'),
                    ':id' => $row['id'],
                ]);
                $allowed = true;
            } elseif ((int)$row['attempts'] >= $limit) {
                $allowed = false;
            } else {
                $increment = $this->pdo->prepare(
                    'UPDATE auth_rate_limits SET attempts = attempts + 1, updated_at = :updated_at WHERE id = :id'
                );
                $increment->execute([':updated_at' => $now->format('Y-m-d H:i:s'), ':id' => $row['id']]);
                $allowed = true;
            }
            $this->pdo->commit();
            return $allowed;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}

final class RateLimitExceededException extends RuntimeException
{
}

