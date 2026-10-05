<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class ProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT id, app_key, project_name, frontend_url, frontend_public, schema_json, enabled, created_by_user_id, created_at, updated_at FROM projects WHERE is_deleted = 0';
        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $statement = $this->pdo->query($sql);
        return array_map(fn(array $row): array => $this->decode($row), $statement->fetchAll());
    }

    /** Add active activity counts to visible projects, including projects from static configuration. */
    public function withActivityCounts(array $projects): array
    {
        if ($projects === []) return [];

        $keys = array_values(array_unique(array_column($projects, 'app_key')));
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT app_key, COUNT(*) AS activity_count FROM activities "
            . "WHERE is_deleted = 0 AND app_key IN ($placeholders) GROUP BY app_key"
        );
        $statement->execute($keys);
        $counts = [];
        foreach ($statement->fetchAll() as $row) $counts[$row['app_key']] = (int)$row['activity_count'];
        foreach ($projects as &$project) $project['activity_count'] = $counts[$project['app_key']] ?? 0;
        unset($project);
        return $projects;
    }

    public function deletedKeys(): array
    {
        return $this->pdo->query('SELECT app_key FROM projects WHERE is_deleted = 1')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function listDeleted(): array
    {
        $statement = $this->pdo->query(
            'SELECT p.id, p.app_key, p.project_name, p.deleted_at, p.created_at, p.updated_at, '
            . '(SELECT COUNT(*) FROM activities a WHERE a.app_key = p.app_key) AS activity_count, '
            . '(SELECT COUNT(*) FROM user_projects up WHERE up.project_id = p.id) AS assignment_count '
            . 'FROM projects p WHERE p.is_deleted = 1 ORDER BY p.deleted_at DESC, p.id DESC'
        );
        return $statement->fetchAll();
    }

    public function restore(string $appKey): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE projects SET is_deleted = 0, deleted_at = NULL, updated_at = :updated_at '
            . 'WHERE app_key = :app_key AND is_deleted = 1'
        );
        $statement->execute([':app_key' => $appKey, ':updated_at' => gmdate('Y-m-d H:i:s')]);
        return $statement->rowCount() > 0;
    }

    /** Called inside a transaction after administrator authorization. */
    public function purge(string $appKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, app_key FROM projects WHERE app_key = :app_key AND is_deleted = 1 FOR UPDATE');
        $statement->execute([':app_key' => $appKey]);
        $project = $statement->fetch();
        if ($project === false) return null;
        $activities = $this->pdo->prepare('DELETE FROM activities WHERE app_key = :app_key');
        $activities->execute([':app_key' => $appKey]);
        $assignments = $this->pdo->prepare('SELECT COUNT(*) FROM user_projects WHERE project_id = :project_id');
        $assignments->execute([':project_id' => $project['id']]);
        $assignmentCount = (int)$assignments->fetchColumn();
        $delete = $this->pdo->prepare('DELETE FROM projects WHERE id = :id AND is_deleted = 1');
        $delete->execute([':id' => $project['id']]);
        return ['id' => (int)$project['id'], 'activities' => $activities->rowCount(), 'assignments' => $assignmentCount];
    }

    public function find(string $appKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, app_key, project_name, frontend_url, frontend_public, schema_json, enabled, is_deleted, created_by_user_id, created_at, updated_at '
            . 'FROM projects WHERE app_key = :app_key LIMIT 1'
        );
        $statement->execute([':app_key' => $appKey]);
        $row = $statement->fetch();
        return $row === false ? null : $this->decode($row);
    }

    public function create(string $appKey, string $projectName, array $schema, bool $enabled = true, ?string $frontendUrl = null, bool $frontendPublic = false, ?int $createdByUserId = null): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO projects (app_key, project_name, frontend_url, frontend_public, schema_json, enabled, created_by_user_id, created_at, updated_at) '
            . 'VALUES (:app_key, :project_name, :frontend_url, :frontend_public, :schema_json, :enabled, :created_by_user_id, :created_at, :updated_at)'
        );
        try {
            $statement->execute([
                ':app_key' => $appKey,
                ':project_name' => $projectName,
                ':frontend_url' => $frontendUrl,
                ':frontend_public' => $frontendPublic ? 1 : 0,
                ':schema_json' => $this->encode($schema),
                ':enabled' => 1,
                ':created_by_user_id' => $createdByUserId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DuplicateProjectException('Project already exists.', 0, $error);
            }
            throw $error;
        }
        return $this->find($appKey)
            ?? throw new RuntimeException('Created project could not be loaded.');
    }

    public function update(string $appKey, ?string $projectName, ?array $schema, ?bool $enabled = null, ?string $frontendUrl = null, ?bool $frontendPublic = null): ?array
    {
        $current = $this->find($appKey);
        if ($current === null) return null;

        $name = $projectName ?? $current['project_name'];
        $schemaData = $schema ?? $current['schema'];
        $url = $frontendUrl === null ? $current['frontend_url'] : ($frontendUrl === '' ? null : $frontendUrl);
        $isPublic = $frontendPublic ?? $current['frontend_public'];
        $isEnabled = 1;

        $statement = $this->pdo->prepare(
            'UPDATE projects SET project_name = :project_name, frontend_url = :frontend_url, frontend_public = :frontend_public, schema_json = :schema_json, enabled = :enabled, updated_at = :updated_at '
            . 'WHERE app_key = :app_key AND is_deleted = 0'
        );
        $statement->execute([
            ':project_name' => $name,
            ':frontend_url' => $url,
            ':frontend_public' => $isPublic ? 1 : 0,
            ':schema_json' => $this->encode($schemaData),
            ':enabled' => $isEnabled,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':app_key' => $appKey,
        ]);
        return $this->find($appKey);
    }

    public function delete(string $appKey): bool
    {
        $statement = $this->pdo->prepare('UPDATE projects SET is_deleted = 1, deleted_at = :deleted_at, updated_at = :updated_at WHERE app_key = :app_key AND is_deleted = 0');
        $now = gmdate('Y-m-d H:i:s');
        $statement->execute([':app_key' => $appKey, ':deleted_at' => $now, ':updated_at' => $now]);
        return $statement->rowCount() > 0;
    }

    private function encode(array $data): string
    {
        return json_encode((object)$data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(array $row): array
    {
        $json = trim((string)$row['schema_json']);
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Stored schema JSON is invalid.', 0, $error);
        }
        $row['schema'] = is_array($data) ? $data : [];
        $row['enabled'] = true;
        $row['is_deleted'] = (bool)($row['is_deleted'] ?? false);
        $row['frontend_url'] = $row['frontend_url'] === null ? null : (string)$row['frontend_url'];
        $row['frontend_public'] = (bool)$row['frontend_public'];
        $row['created_by_user_id'] = $row['created_by_user_id'] === null ? null : (int)$row['created_by_user_id'];
        unset($row['schema_json']);
        return $row;
    }
}

final class DuplicateProjectException extends RuntimeException
{
}
