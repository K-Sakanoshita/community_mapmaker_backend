<?php
declare(strict_types=1);
namespace CommunityMapMaker\Auth;

use CommunityMapMaker\Activity\ProjectNotFoundException;
use PDO;
use RuntimeException;
use Throwable;

final class MyProjects
{
    private const MEMBER_ROLES = ['viewer', 'contributor', 'editor', 'project_admin'];
    public function __construct(private PDO $pdo, private AuditLogRepositoryInterface $audit) {}

    public function list(int $userId, int $page = 1, int $perPage = 25): array
    {
        $this->assertActor($userId);
        $perPage = min(100, max(1, $perPage));
        $where = " FROM user_projects up JOIN projects p ON p.id = up.project_id WHERE up.user_id = :user AND p.is_deleted = 0 AND up.role IN ('viewer','contributor','editor','project_admin')";
        $query = $this->pdo->prepare('SELECT COUNT(*)' . $where);
        $query->execute([':user' => $userId]);
        $total = (int)$query->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, $page));
        $query = $this->pdo->prepare('SELECT p.app_key, p.project_name, p.enabled, p.frontend_url, p.frontend_public, p.created_by_user_id, up.role, '
            . "(SELECT COUNT(*) FROM user_projects others JOIN users u ON u.id = others.user_id WHERE others.project_id = p.id AND others.user_id <> up.user_id AND others.role = 'project_admin' AND u.status = 'active' AND u.role IN ('user','admin')) AS other_admins"
            . $where . ' ORDER BY p.project_name, p.id LIMIT :limit OFFSET :offset');
        $query->bindValue(':user', $userId, PDO::PARAM_INT);
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $query->execute();
        $items = array_map(function(array $row) use ($userId): array {
            $reason = $this->leaveReason($userId, $row['created_by_user_id'], $row['role'], (int)$row['other_admins']);
            return ['app_key' => $row['app_key'], 'project_name' => $row['project_name'], 'role' => $row['role'],
                'enabled' => (bool)$row['enabled'], 'frontend_url' => $row['frontend_url'], 'frontend_public' => (bool)$row['frontend_public'],
                'can_leave' => $reason === null, 'leave_reason' => $reason];
        }, $query->fetchAll());
        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $pages]];
    }

    /** Read only the requested membership; the full paginated list is unnecessary on a detail page. */
    public function leaveStatus(int $userId, string $appKey): array
    {
        $this->assertActor($userId);
        $query = $this->pdo->prepare("SELECT p.created_by_user_id, up.role, "
            . "(SELECT COUNT(*) FROM user_projects others JOIN users u ON u.id = others.user_id WHERE others.project_id = p.id AND others.user_id <> up.user_id AND others.role = 'project_admin' AND u.status = 'active' AND u.role IN ('user','admin')) AS other_admins "
            . "FROM user_projects up JOIN projects p ON p.id = up.project_id WHERE up.user_id = ? AND p.app_key = ? AND p.is_deleted = 0");
        $query->execute([$userId, $appKey]);
        $row = $query->fetch();
        if (!$row || !in_array($row['role'], self::MEMBER_ROLES, true)) return ['can_leave' => false, 'leave_reason' => null];
        $reason = $this->leaveReason($userId, $row['created_by_user_id'], $row['role'], (int)$row['other_admins']);
        return ['can_leave' => $reason === null, 'leave_reason' => $reason];
    }

    /** The actor ID comes from authentication, never from the request body. */
    public function leave(int $userId, string $appKey): array
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id, created_by_user_id FROM projects WHERE app_key = ? AND is_deleted = 0 FOR UPDATE');
            $query->execute([$appKey]);
            $project = $query->fetch();
            if (!$project) throw new ProjectNotFoundException('Project not found.');
            $this->assertActor($userId, true);
            $query = $this->pdo->prepare('SELECT role FROM user_projects WHERE project_id = ? AND user_id = ? FOR UPDATE');
            $query->execute([$project['id'], $userId]);
            $role = $query->fetchColumn();
            if ($role === false) throw new ProjectNotFoundException('Project not found.');
            if ($role === 'banned') throw new MyProjectsDenied('project_participation_banned');
            if (!in_array($role, self::MEMBER_ROLES, true)) throw new MyProjectsDenied('invalid_membership');
            $otherAdmins = 0;
            if ($role === 'project_admin') {
                $query = $this->pdo->prepare("SELECT u.id FROM user_projects up JOIN users u ON u.id = up.user_id WHERE up.project_id = ? AND up.user_id <> ? AND up.role = 'project_admin' AND u.status = 'active' AND u.role IN ('user','admin') FOR UPDATE");
                $query->execute([$project['id'], $userId]);
                $otherAdmins = count($query->fetchAll());
            }
            $reason = $this->leaveReason($userId, $project['created_by_user_id'], $role, $otherAdmins);
            if ($reason !== null) throw new MyProjectsDenied($reason);
            $query = $this->pdo->prepare('DELETE FROM user_projects WHERE project_id = ? AND user_id = ?');
            $query->execute([$project['id'], $userId]);
            $this->audit->record($userId, 'leave_project', 'project', (int)$project['id'], ['app_key' => $appKey, 'previous_role' => $role]);
            $this->pdo->commit();
            return ['status' => 'ok'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function assertActor(int $userId, bool $lock = false): void
    {
        $query = $this->pdo->prepare('SELECT status, role FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || $user['status'] !== 'active' || !in_array($user['role'], ['user', 'admin'], true)) throw new MyProjectsDenied('authentication_required');
    }

    private function leaveReason(int $userId, mixed $creatorId, string $role, int $otherAdmins): ?string
    {
        if ($creatorId !== null && (int)$creatorId === $userId) return 'project_creator_required';
        if ($role === 'project_admin' && $otherAdmins === 0) return 'last_project_administrator';
        return null;
    }
}
final class MyProjectsDenied extends RuntimeException {}
