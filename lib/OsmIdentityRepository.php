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

    public function updateDisplayName(int $userId, string $subject, string $displayName): void
    {
        $query = $this->pdo->prepare('UPDATE osm_identities SET osm_display_name = ? WHERE user_id = ? AND osm_subject = ?');
        $query->execute([$displayName, $userId, $subject]);
    }

    public function link(int $userId, string $subject, ?string $displayName = null): bool
    {
        $query = $this->pdo->prepare(
            "INSERT INTO osm_identities (user_id, osm_subject, osm_display_name)
             SELECT id, ?, ? FROM users WHERE id = ? AND status = 'active' AND role IN ('user', 'admin')"
        );
        try {
            $query->execute([$subject, $displayName, $userId]);
            return $query->rowCount() === 1;
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') throw $error;
            return false;
        }
    }
}

final class OsmIdentityAlreadyLinkedException extends \RuntimeException {}
