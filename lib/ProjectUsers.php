<?php
declare(strict_types=1);
namespace CommunityMapMaker\Auth;

use CommunityMapMaker\Activity\ProjectNotFoundException;
use PDO;
use Throwable;

/** Project membership management only; account details belong to the global admin API. */
final class ProjectUsers
{
    private const ROLES = ['viewer', 'contributor', 'editor', 'project_admin', 'banned'];
    public function __construct(private PDO $pdo, private AuditLogRepositoryInterface $audit) {}

    public function execute(int $actorId, string $appKey, string $method, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id, created_by_user_id FROM projects WHERE app_key = ? AND is_deleted = 0 FOR UPDATE');
            $query->execute([$appKey]);
            $project = $query->fetch();
            if (!$project) throw new ProjectNotFoundException('Project not found.');
            $query = $this->pdo->prepare('SELECT id, status, role FROM users WHERE id = ? FOR UPDATE');
            $query->execute([$actorId]);
            $actor = $query->fetch();
            $membership = $this->pdo->prepare('SELECT role FROM user_projects WHERE project_id = ? AND user_id = ? FOR UPDATE');
            $membership->execute([$project['id'], $actorId]);
            $actorRole = $membership->fetchColumn();
            if (!$actor || $actor['status'] !== 'active' || !in_array($actor['role'], ['user', 'admin'], true)
                || ($actor['role'] !== 'admin' && $actorRole !== 'project_admin')) {
                throw new ProjectAccessDeniedException('Project user management is not permitted.');
            }
            if ($method === 'PUT') {
                foreach (array_keys($input) as $key) {
                    if (!in_array($key, ['app', 'id', 'role'], true)) throw new ValidationException([$key => 'Only project membership can be changed.']);
                }
                $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $role = $input['role'] ?? null;
                if (!$id || !array_key_exists('role', $input) || ($role !== null && !in_array($role, self::ROLES, true))) {
                    throw new ValidationException(['role' => 'Choose a project role or null to remove membership.']);
                }
                $target = $this->pdo->prepare('SELECT role FROM users WHERE id = ? AND status <> \'deleted\' FOR UPDATE');
                $target->execute([$id]);
                $accountRole = $target->fetchColumn();
                $membership->execute([$project['id'], $id]);
                $previous = $membership->fetchColumn();
                if ($accountRole === false || $previous === false) throw new ValidationException(['id' => 'This user is not assigned to this project.']);
                if ($accountRole === 'admin') throw new ValidationException(['id' => 'Manage global administrators in all-user management.']);
                if ($previous === 'project_admin' && $role !== 'project_admin') {
                    if ((int)$project['created_by_user_id'] === $id) throw new ProjectUserConflict('project_creator_required');
                    $remaining = $this->pdo->prepare("SELECT u.id FROM user_projects up JOIN users u ON u.id = up.user_id WHERE up.project_id = ? AND up.user_id <> ? AND up.role = 'project_admin' AND u.status = 'active' AND u.role IN ('user','admin') FOR UPDATE");
                    $remaining->execute([$project['id'], $id]);
                    if ($remaining->fetchColumn() === false) throw new ProjectUserConflict('last_project_administrator');
                }
                if ($role === null) {
                    $change = $this->pdo->prepare('DELETE FROM user_projects WHERE project_id = ? AND user_id = ?');
                    $change->execute([$project['id'], $id]);
                } else {
                    $change = $this->pdo->prepare('UPDATE user_projects SET role = ? WHERE project_id = ? AND user_id = ?');
                    $change->execute([$role, $project['id'], $id]);
                }
                $this->audit->record($actorId, 'project.user.update', 'project', (int)$project['id'], ['user_id' => $id, 'from' => $previous, 'to' => $role]);
                $result = ['status' => 'ok'];
            } elseif ($method === 'GET') {
                $page = max(1, (int)($input['page'] ?? 1));
                $limit = min(100, max(1, (int)($input['per_page'] ?? 25)));
                $search = trim((string)($input['search'] ?? ''));
                if (mb_strlen($search) > 255) throw new ValidationException(['search' => 'Search is too long.']);
                $where = ' FROM user_projects up JOIN users u ON u.id = up.user_id WHERE u.status <> \'deleted\' AND up.project_id = :project AND LOCATE(:search, u.userid) > 0';
                $params = [':project' => $project['id'], ':search' => $search];
                $count = $this->pdo->prepare('SELECT COUNT(*)' . $where); $count->execute($params);
                $total = (int)$count->fetchColumn();
                $query = $this->pdo->prepare('SELECT u.id, u.userid, u.role AS account_role, up.role AS project_role' . $where . ' ORDER BY u.userid, u.id LIMIT :limit OFFSET :offset');
                foreach ($params as $key => $value) $query->bindValue($key, $value);
                $query->bindValue(':limit', $limit, PDO::PARAM_INT);
                $query->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
                $query->execute();
                $result = ['items' => array_map(static fn(array $row): array => [
                    'id' => (int)$row['id'], 'userid' => $row['userid'], 'project_role' => $row['project_role'],
                    'is_global_admin' => $row['account_role'] === 'admin',
                ], $query->fetchAll()), 'pagination' => ['page' => $page, 'per_page' => $limit, 'total' => $total, 'total_pages' => max(1, (int)ceil($total / $limit))]];
            } else throw new ValidationException(['method' => 'Unsupported method.']);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
final class ProjectUserConflict extends \RuntimeException {}
