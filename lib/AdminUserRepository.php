<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;

final class AdminUserRepository implements AdminUserRepositoryInterface, ProjectAccessRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($filters);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM users u ' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql = 'SELECT u.id, u.userid, u.email, u.status, u.role, u.email_verified_at, u.last_login_at, u.created_at, u.updated_at, '
            . '(SELECT COUNT(*) FROM activities a WHERE a.is_deleted = 0 AND a.created_by_user_id = u.id) AS activity_count, '
            . '(SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.id) AS project_count '
            . 'FROM users u ' . $where . ' ORDER BY u.updated_at DESC, u.id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) $statement->bindValue($key, $value);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        return [
            'items' => array_map(fn(array $row): array => $this->safeRow($row), $statement->fetchAll()),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $perPage)),
            ],
        ];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.userid, u.email, u.status, u.role, u.email_verified_at, u.last_login_at, u.created_at, u.updated_at, '
            . '(SELECT COUNT(*) FROM activities a WHERE a.is_deleted = 0 AND a.created_by_user_id = u.id) AS activity_count, '
            . '(SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.id) AS project_count '
            . 'FROM users u WHERE u.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        if ($row === false) return null;

        $projects = $this->pdo->prepare(
            'SELECT p.id AS project_id, p.app_key, p.project_name, up.role '
            . 'FROM user_projects up JOIN projects p ON p.id = up.project_id '
            . 'WHERE up.user_id = :id ORDER BY p.project_name, p.id'
        );
        $projects->execute([':id' => $id]);

        $activities = $this->pdo->prepare(
            'SELECT app_key, activity_key, created_at, updated_at, '
            . 'CASE WHEN created_by_user_id = :creator_id THEN 1 ELSE 0 END AS created_by_user '
            . 'FROM activities WHERE is_deleted = 0 AND (created_by_user_id = :created_id OR updated_by_user_id = :updated_id) '
            . 'ORDER BY updated_at DESC, id DESC LIMIT 20'
        );
        $activities->execute([':creator_id' => $id, ':created_id' => $id, ':updated_id' => $id]);

        $result = $this->safeRow($row);
        $result['projects'] = array_map(static fn(array $project): array => [
            'project_id' => (int)$project['project_id'],
            'app_key' => (string)$project['app_key'],
            'project_name' => (string)$project['project_name'],
            'role' => (string)$project['role'],
        ], $projects->fetchAll());
        $result['recent_activities'] = array_map(static fn(array $activity): array => [
            'app_key' => (string)$activity['app_key'],
            'activity_key' => (string)$activity['activity_key'],
            'created_at' => (string)$activity['created_at'],
            'updated_at' => (string)$activity['updated_at'],
            'created_by_user' => (bool)$activity['created_by_user'],
        ], $activities->fetchAll());
        return $result;
    }

    public function update(int $id, string $status, string $role): ?array
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET status = :status, role = :role, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':role' => $role,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
        return $this->find($id);
    }

    public function countActiveAdmins(): int
    {
        $statement = $this->pdo->query(
            "SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE"
        );
        return count($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function projectsExist(array $projectIds): bool
    {
        if ($projectIds === []) return true;
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE id IN (' . $placeholders . ')');
        $statement->execute(array_values($projectIds));
        return (int)$statement->fetchColumn() === count(array_unique($projectIds));
    }

    public function setProjects(int $userId, array $projects): void
    {
        $delete = $this->pdo->prepare('DELETE FROM user_projects WHERE user_id = :user_id');
        $delete->execute([':user_id' => $userId]);
        if ($projects === []) return;
        $insert = $this->pdo->prepare(
            'INSERT INTO user_projects (user_id, project_id, role, created_at) VALUES (:user_id, :project_id, :role, :created_at)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($projects as $project) {
            $insert->execute([
                ':user_id' => $userId,
                ':project_id' => $project['project_id'],
                ':role' => $project['role'],
                ':created_at' => $now,
            ]);
        }
    }

    public function rolesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.app_key, up.role FROM user_projects up '
            . 'JOIN projects p ON p.id = up.project_id WHERE up.user_id = :user_id'
        );
        $statement->execute([':user_id' => $userId]);
        $roles = [];
        foreach ($statement->fetchAll() as $row) {
            $roles[(string)$row['app_key']] = (string)$row['role'];
        }
        return $roles;
    }

    public function roleForUser(int $userId, string $appKey): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT up.role FROM user_projects up JOIN projects p ON p.id = up.project_id '
            . 'WHERE up.user_id = :user_id AND p.app_key = :app_key LIMIT 1'
        );
        $statement->execute([':user_id' => $userId, ':app_key' => $appKey]);
        $role = $statement->fetchColumn();
        return $role === false ? null : (string)$role;
    }

    private function where(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (($filters['search'] ?? '') !== '') {
            $clauses[] = '(LOCATE(:search_userid, LOWER(u.userid)) > 0 OR LOCATE(:search_email, LOWER(u.email)) > 0)';
            $params[':search_userid'] = $filters['search'];
            $params[':search_email'] = $filters['search'];
        }
        if (($filters['status'] ?? '') !== '') {
            $clauses[] = 'u.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (($filters['role'] ?? '') !== '') {
            $clauses[] = 'u.role = :role';
            $params[':role'] = $filters['role'];
        }
        if (($filters['verified'] ?? '') === 'yes') $clauses[] = 'u.email_verified_at IS NOT NULL';
        if (($filters['verified'] ?? '') === 'no') $clauses[] = 'u.email_verified_at IS NULL';
        if (($filters['project_id'] ?? 0) > 0) {
            $clauses[] = 'EXISTS (SELECT 1 FROM user_projects fp WHERE fp.user_id = u.id AND fp.project_id = :project_id)';
            $params[':project_id'] = (int)$filters['project_id'];
        }
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function safeRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'userid' => (string)$row['userid'],
            'email' => $row['email'] === null ? null : (string)$row['email'],
            'status' => (string)$row['status'],
            'role' => (string)$row['role'],
            'email_verified_at' => $row['email_verified_at'] === null ? null : (string)$row['email_verified_at'],
            'last_login_at' => $row['last_login_at'] === null ? null : (string)$row['last_login_at'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
            'activity_count' => (int)$row['activity_count'],
            'project_count' => (int)$row['project_count'],
        ];
    }
}
