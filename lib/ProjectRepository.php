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
        $sql = 'SELECT id, app_key, project_name, schema_json, enabled, created_at, updated_at FROM projects';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $statement = $this->pdo->query($sql);
        return array_map(fn(array $row): array => $this->decode($row), $statement->fetchAll());
    }

    public function find(string $appKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, app_key, project_name, schema_json, enabled, created_at, updated_at '
            . 'FROM projects WHERE app_key = :app_key LIMIT 1'
        );
        $statement->execute([':app_key' => $appKey]);
        $row = $statement->fetch();
        return $row === false ? null : $this->decode($row);
    }

    public function create(string $appKey, string $projectName, array $schema, bool $enabled = true): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO projects (app_key, project_name, schema_json, enabled, created_at, updated_at) '
            . 'VALUES (:app_key, :project_name, :schema_json, :enabled, :created_at, :updated_at)'
        );
        try {
            $statement->execute([
                ':app_key' => $appKey,
                ':project_name' => $projectName,
                ':schema_json' => $this->encode($schema),
                ':enabled' => $enabled ? 1 : 0,
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

    public function update(string $appKey, ?string $projectName, ?array $schema, ?bool $enabled = null): ?array
    {
        $current = $this->find($appKey);
        if ($current === null) return null;

        $name = $projectName ?? $current['project_name'];
        $schemaData = $schema ?? $current['schema'];
        $isEnabled = $enabled !== null ? ($enabled ? 1 : 0) : ($current['enabled'] ? 1 : 0);

        $statement = $this->pdo->prepare(
            'UPDATE projects SET project_name = :project_name, schema_json = :schema_json, enabled = :enabled, updated_at = :updated_at '
            . 'WHERE app_key = :app_key'
        );
        $statement->execute([
            ':project_name' => $name,
            ':schema_json' => $this->encode($schemaData),
            ':enabled' => $isEnabled,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':app_key' => $appKey,
        ]);
        return $this->find($appKey);
    }

    public function delete(string $appKey): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM projects WHERE app_key = :app_key');
        $statement->execute([':app_key' => $appKey]);
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
        $row['enabled'] = (bool)$row['enabled'];
        unset($row['schema_json']);
        return $row;
    }
}

final class DuplicateProjectException extends RuntimeException
{
}
