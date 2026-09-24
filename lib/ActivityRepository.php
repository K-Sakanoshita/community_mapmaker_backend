<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class ActivityRepository implements ActivityRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(string $appKey, ?string $osmid = null): array
    {
        $sql = 'SELECT id, app_key, activity_key, form_key, osmid, latitude, longitude, data_json, created_by_user_id, updated_by_user_id, created_at, updated_at '
            . 'FROM activities WHERE is_deleted = 0 AND app_key = :app_key';
        $params = [':app_key' => $appKey];
        if ($osmid !== null) {
            $sql .= ' AND osmid = :osmid';
            $params[':osmid'] = $osmid;
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(fn(array $row): array => $this->decode($row), $statement->fetchAll());
    }

    public function searchRows(string $appKey, ?array $bbox = null, ?array $osmids = null): array
    {
        if ($osmids === []) return [];
        $sql = 'SELECT id, app_key, activity_key, form_key, osmid, latitude, longitude, data_json, created_by_user_id, updated_by_user_id, created_at, updated_at '
            . 'FROM activities WHERE is_deleted = 0 AND app_key = :app_key';
        $params = [':app_key' => $appKey];
        if ($bbox !== null) {
            $sql .= ' AND longitude BETWEEN :west AND :east AND latitude BETWEEN :south AND :north';
            [$params[':west'], $params[':south'], $params[':east'], $params[':north']] = $bbox;
        }
        if ($osmids !== null) {
            $placeholders = [];
            foreach ($osmids as $index => $osmid) {
                $placeholder = ':osmid_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $osmid;
            }
            $sql .= ' AND osmid IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(fn(array $row): array => $this->decode($row), $statement->fetchAll());
    }

    public function find(string $appKey, string $activityKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, app_key, activity_key, form_key, osmid, latitude, longitude, data_json, created_by_user_id, updated_by_user_id, created_at, updated_at '
            . 'FROM activities WHERE is_deleted = 0 AND app_key = :app_key AND activity_key = :activity_key LIMIT 1'
        );
        $statement->execute([':app_key' => $appKey, ':activity_key' => $activityKey]);
        $row = $statement->fetch();
        return $row === false ? null : $this->decode($row);
    }

    public function findForImport(string $appKey, string $activityKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, app_key, activity_key, form_key, osmid, latitude, longitude, data_json, created_by_user_id, updated_by_user_id, created_at, updated_at, is_deleted '
            . 'FROM activities WHERE app_key = :app_key AND activity_key = :activity_key LIMIT 1'
        );
        $statement->execute([':app_key' => $appKey, ':activity_key' => $activityKey]);
        $row = $statement->fetch();
        return $row === false ? null : $this->decode($row);
    }

    public function restoreForImport(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array
    {
        $statement = $this->pdo->prepare(
            'UPDATE activities SET is_deleted = 0, deleted_at = NULL, '
            . ($coordinates === null ? '' : 'latitude = :latitude, longitude = :longitude, ')
            . 'form_key = :form_key, osmid = :osmid, data_json = :data_json, updated_by_user_id = :updated_by_user_id, updated_at = :updated_at '
            . 'WHERE is_deleted = 1 AND app_key = :app_key AND activity_key = :activity_key'
        );
        $params = [':form_key' => $formKey, ':osmid' => $osmid, ':data_json' => $this->encode($data),
            ':updated_by_user_id' => $updatedByUserId, ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':app_key' => $appKey, ':activity_key' => $activityKey];
        if ($coordinates !== null) {
            $params[':latitude'] = $coordinates['latitude'];
            $params[':longitude'] = $coordinates['longitude'];
        }
        $statement->execute($params);
        return $statement->rowCount() === 0 ? null : $this->find($appKey, $activityKey);
    }

    public function create(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $createdByUserId = null, ?array $coordinates = null): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO activities (app_key, activity_key, form_key, osmid, latitude, longitude, data_json, created_by_user_id, updated_by_user_id, created_at, updated_at) '
            . 'VALUES (:app_key, :activity_key, :form_key, :osmid, :latitude, :longitude, :data_json, :created_by_user_id, :updated_by_user_id, :created_at, :updated_at)'
        );
        try {
            $statement->execute([
                ':app_key' => $appKey,
                ':activity_key' => $activityKey,
                ':form_key' => $formKey,
                ':osmid' => $osmid,
                ':latitude' => $coordinates['latitude'] ?? null,
                ':longitude' => $coordinates['longitude'] ?? null,
                ':data_json' => $this->encode($data),
                ':created_by_user_id' => $createdByUserId,
                ':updated_by_user_id' => $createdByUserId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DuplicateActivityException('Activity already exists.', 0, $error);
            }
            throw $error;
        }
        return $this->find($appKey, $activityKey)
            ?? throw new RuntimeException('Created activity could not be loaded.');
    }

    public function update(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array
    {
        $statement = $this->pdo->prepare(
            'UPDATE activities SET ' . ($coordinates === null ? '' : 'latitude = :latitude, longitude = :longitude, ') . 'form_key = :form_key, osmid = :osmid, data_json = :data_json, updated_by_user_id = :updated_by_user_id, updated_at = :updated_at '
            . 'WHERE is_deleted = 0 AND app_key = :app_key AND activity_key = :activity_key'
        );
        $params = [
            ':form_key' => $formKey,
            ':osmid' => $osmid,
            ':data_json' => $this->encode($data),
            ':updated_by_user_id' => $updatedByUserId,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':app_key' => $appKey,
            ':activity_key' => $activityKey,
        ];
        if ($coordinates !== null) {
            $params[':latitude'] = $coordinates['latitude'];
            $params[':longitude'] = $coordinates['longitude'];
        }
        $statement->execute($params);
        return $statement->rowCount() === 0 && $this->find($appKey, $activityKey) === null
            ? null
            : $this->find($appKey, $activityKey);
    }

    public function delete(string $appKey, string $activityKey): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE activities SET is_deleted = 1, deleted_at = :deleted_at, updated_at = :updated_at '
            . 'WHERE is_deleted = 0 AND app_key = :app_key AND activity_key = :activity_key'
        );
        $now = gmdate('Y-m-d H:i:s');
        $statement->execute([':app_key' => $appKey, ':activity_key' => $activityKey, ':deleted_at' => $now, ':updated_at' => $now]);
        return $statement->rowCount() > 0;
    }

    private function encode(array $data): string
    {
        return json_encode((object)$data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(array $row): array
    {
        $json = trim((string)$row['data_json']);
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Stored activity JSON is invalid.', 0, $error);
        }
        if (!str_starts_with($json, '{') || !is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException('Stored activity JSON must be an object.');
        }
        $row['data'] = $data;
        unset($row['data_json']);
        return $row;
    }
}

final class DuplicateActivityException extends RuntimeException
{
}
