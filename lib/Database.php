<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use Throwable;

final class Database implements TransactionManagerInterface
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $this->pdo = new PDO(
            (string)($config['dsn'] ?? ''),
            (string)($config['user'] ?? ''),
            (string)($config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}

