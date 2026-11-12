<?php
declare(strict_types=1);
namespace CommunityMapMaker\Auth;

use CommunityMapMaker\Activity\ProjectNotFoundException;
use PDO;
use Throwable;

final class ProjectAdministrators
{
    public function __construct(private PDO $pdo, private AuditLogRepository $audit) {}

    /** Manage only project-level administrators, never global user roles or credentials. */
    public function execute(int $actorId, string $appKey, string $method, mixed $userid = null): array
    {
        if ($appKey === '' || strlen($appKey) > 64) throw new ValidationException(['app' => 'Project key is required.']);
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) throw new ValidationException(['method' => 'Unsupported method.']);
        $this->pdo->beginTransaction();
        try {
            // Serialize membership changes for this project and recheck access under the lock.
            $query = $this->pdo->prepare('SELECT id, app_key, project_name, created_by_user_id FROM projects WHERE app_key = :app AND is_deleted = 0 FOR UPDATE');
            $query->execute([':app' => $appKey]);
            $project = $query->fetch();
            if (!$project) throw new ProjectNotFoundException('Project not found.');
            $query = $this->pdo->prepare('SELECT id, role, status FROM users WHERE id = :id FOR UPDATE');
            $query->execute([':id' => $actorId]);
            $actor = $query->fetch();
            $query = $this->pdo->prepare('SELECT role FROM user_projects WHERE user_id = :user AND project_id = :project FOR UPDATE');
            $query->execute([':user' => $actorId, ':project' => $project['id']]);
            $actorRole = $query->fetchColumn();
            if (!$actor || $actor['status'] !== 'active' || !in_array($actor['role'], ['user', 'admin'], true)
                || ($actor['role'] !== 'admin' && $actorRole !== 'project_admin')) {
                throw new ProjectAccessDeniedException('Project administration is not permitted.');
            }
            if ($method !== 'GET') {
                if (!is_string($userid) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,63}$/D', trim($userid))) {
                    throw new ValidationException(['userid' => 'Enter an existing user ID.']);
                }
                $query = $this->pdo->prepare('SELECT id, userid, status, role FROM users WHERE userid_normalized = :userid FOR UPDATE');
                $query->execute([':userid' => strtolower(trim($userid))]);
                $target = $query->fetch();
                if (!$target || $target['status'] === 'deleted') throw new ValidationException(['userid' => 'This user cannot be assigned as a project administrator.']);
                $query = $this->pdo->prepare('SELECT role FROM user_projects WHERE user_id = :user AND project_id = :project FOR UPDATE');
                $query->execute([':user' => $target['id'], ':project' => $project['id']]);
                $previous = $query->fetchColumn();
                if ($method === 'POST') {
                    if ($previous === 'banned') throw new ValidationException(['userid' => 'Remove the participation ban in user management first.']);
                    if ($target['status'] !== 'active' || !in_array($target['role'], ['user', 'admin'], true)) {
                        throw new ValidationException(['userid' => 'This user cannot be assigned as a project administrator.']);
                    }
                    if ($previous !== 'project_admin') {
                        $change = $this->pdo->prepare("INSERT INTO user_projects (user_id, project_id, role, created_at) VALUES (:user, :project, 'project_admin', :created) ON DUPLICATE KEY UPDATE role = 'project_admin'");
                        $change->execute([':user' => $target['id'], ':project' => $project['id'], ':created' => gmdate('Y-m-d H:i:s')]);
                        $this->audit->record($actorId, 'project.admin.add', 'project', (int)$project['id'], ['user_id' => (int)$target['id'], 'previous_role' => $previous === false ? null : $previous]);
                    }
                } else {
                    if ($previous !== 'project_admin') throw new ValidationException(['userid' => 'This user is not a project administrator.']);
                    if ((int)($project['created_by_user_id'] ?? 0) === (int)$target['id']) {
                        throw new ProjectAdministratorConflict('project_creator_required');
                    }
                    $remaining = $this->pdo->prepare("SELECT u.id FROM user_projects up JOIN users u ON u.id = up.user_id WHERE up.project_id = :project AND up.role = 'project_admin' AND u.status = 'active' AND u.role IN ('user','admin') AND u.id <> :target FOR UPDATE");
                    $remaining->execute([':project' => $project['id'], ':target' => $target['id']]);
                    if ($remaining->fetchColumn() === false) throw new ProjectAdministratorConflict('last_project_administrator');
                    $change = $this->pdo->prepare("UPDATE user_projects SET role = 'editor' WHERE user_id = :user AND project_id = :project AND role = 'project_admin'");
                    $change->execute([':user' => $target['id'], ':project' => $project['id']]);
                    $this->audit->record($actorId, 'project.admin.remove', 'project', (int)$project['id'], ['user_id' => (int)$target['id'], 'role' => 'editor']);
                }
            }
            $query = $this->pdo->prepare("SELECT u.id, u.userid, u.status, u.role AS account_role FROM user_projects up JOIN users u ON u.id = up.user_id WHERE up.project_id = :project AND up.role = 'project_admin' AND u.status <> 'deleted' ORDER BY u.userid_normalized, u.id");
            $query->execute([':project' => $project['id']]);
            $items = array_map(static fn(array $row): array => [
                'id' => (int)$row['id'], 'userid' => $row['userid'], 'status' => $row['status'],
                'can_manage' => $row['status'] === 'active' && in_array($row['account_role'], ['user', 'admin'], true),
                'is_creator' => (int)($project['created_by_user_id'] ?? 0) === (int)$row['id'],
            ], $query->fetchAll());
            $this->pdo->commit();
            return ['app_key' => $project['app_key'], 'project_name' => $project['project_name'], 'administrators' => $items];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}

final class ProjectAdministratorConflict extends \RuntimeException {}
