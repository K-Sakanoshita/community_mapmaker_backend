<?php
declare(strict_types=1);
namespace CommunityMapMaker\Auth;

use CommunityMapMaker\Activity\ProjectNotFoundException;
use PDO;
use RuntimeException;
use Throwable;

final class ProjectMembership
{
    public function __construct(private PDO $pdo, private AuditLogRepositoryInterface $audit) {}

    public function status(string $appKey, ?array $user): array
    {
        $project = $this->project($appKey, false, false);
        $role = $user === null ? null : $this->role((int)$user['id'], (int)$project['id']);
        if ((!(bool)$project['frontend_public'] || !(bool)$project['enabled']) && ($role === null || $role === 'banned') && ($user['role'] ?? '') !== 'admin') {
            throw new ProjectNotFoundException('Project not found.');
        }
        return $this->presentation($project, $user !== null, $role);
    }

    /** Join only as the authenticated actor, without changing an existing membership. */
    public function join(string $appKey, int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            // Serializes joins with recruitment changes and other joins for this project.
            $project = $this->project($appKey, true);
            $userQuery = $this->pdo->prepare('SELECT status, role FROM users WHERE id = ? FOR UPDATE');
            $userQuery->execute([$userId]);
            $user = $userQuery->fetch();
            if (!$user || $user['status'] !== 'active' || !in_array($user['role'], ['user', 'admin'], true)) {
                throw new ProjectMembershipDenied('authentication_required');
            }
            $role = $this->role($userId, (int)$project['id']);
            if ($role === 'banned') throw new ProjectMembershipDenied('project_participation_banned');
            if ($role === null) {
                if (!(bool)$project['frontend_public'] || !(bool)$project['recruiting']) {
                    throw new ProjectMembershipDenied('project_not_recruiting');
                }
                $insert = $this->pdo->prepare("INSERT INTO user_projects (user_id, project_id, role, created_at) VALUES (?, ?, 'contributor', ?)");
                $insert->execute([$userId, $project['id'], gmdate('Y-m-d H:i:s')]);
                $role = 'contributor';
                $this->audit->record($userId, 'join_project', 'project', (int)$project['id'], ['app_key' => $appKey, 'role' => $role]);
            }
            $this->pdo->commit();
            return $this->presentation($project, true, $role);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function project(string $appKey, bool $lock = false, bool $enabledOnly = true): array
    {
        $query = $this->pdo->prepare('SELECT id, frontend_public, recruiting, enabled FROM projects WHERE app_key = ? AND is_deleted = 0' . ($enabledOnly ? ' AND enabled = 1' : '') . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$appKey]);
        $project = $query->fetch();
        if (!$project) throw new ProjectNotFoundException('Project not found.');
        return $project;
    }

    private function role(int $userId, int $projectId): ?string
    {
        $query = $this->pdo->prepare('SELECT role FROM user_projects WHERE user_id = ? AND project_id = ?');
        $query->execute([$userId, $projectId]);
        $role = $query->fetchColumn();
        return $role === false ? null : (string)$role;
    }

    private function presentation(array $project, bool $authenticated, ?string $role): array
    {
        return ['authenticated' => $authenticated, 'is_member' => $role !== null && $role !== 'banned',
            'can_join' => $role === null && (bool)$project['enabled'] && (bool)$project['frontend_public'] && (bool)$project['recruiting']];
    }
}

final class ProjectMembershipDenied extends RuntimeException {}
