<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use PDOException;

final class OsmIdentityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isLinked(int $userId): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM osm_identities WHERE user_id = ?');
        $query->execute([$userId]);
        return $query->fetchColumn() !== false;
    }

    public function userId(string $subject): ?int
    {
        $query = $this->pdo->prepare('SELECT user_id FROM osm_identities WHERE osm_subject = ?');
        $query->execute([$subject]);
        $id = $query->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function link(int $userId, string $subject): bool
    {
        $query = $this->pdo->prepare(
            "INSERT INTO osm_identities (user_id, osm_subject)
             SELECT id, ? FROM users WHERE id = ? AND status = 'active' AND role IN ('user', 'admin')"
        );
        try {
            $query->execute([$subject, $userId]);
            return $query->rowCount() === 1;
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') throw $error;
            return false;
        }
    }
}
