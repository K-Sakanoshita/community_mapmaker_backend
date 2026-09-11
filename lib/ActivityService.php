<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use CommunityMapMaker\Auth\TransactionManagerInterface;
use RuntimeException;

final class ActivityService
{
    private const RESERVED = [
        'app' => true, 'app_key' => true, 'id' => true, 'activity_key' => true,
        'is_deleted' => true, 'deleted_at' => true,
        'form_key' => true, 'osmid' => true, 'created_at' => true, 'updated_at' => true,
    ];

    public function __construct(
        private ActivityRepositoryInterface $repository,
        private ActivitySchema $schema,
        private ?TransactionManagerInterface $transactions = null
    ) {
    }

    public function list(string $appKey, ?string $osmid = null): array
    {
        $this->schema->appConfig($appKey);
        if ($osmid !== null) $this->validateOsmid($osmid);
        return array_map(fn(array $row): array => $this->flatten($row), $this->repository->list($appKey, $osmid));
    }

    public function find(string $appKey, string $activityKey): array
    {
        $this->schema->appConfig($appKey);
        $this->schema->assertKey($activityKey, 'activity');
        $row = $this->repository->find($appKey, $activityKey);
        if ($row === null) throw new ActivityNotFoundException('Activity not found.');
        return $this->flatten($row);
    }

    public function create(array $input, bool $migration = false, ?int $actorUserId = null): array
    {
        $appKey = $this->appFrom($input);
        $activityKey = trim((string)($input['id'] ?? $input['activity_key'] ?? ''));
        $generated = $activityKey === '';
        if ($generated) $activityKey = $this->generateKey($appKey);
        $this->schema->assertKey($activityKey, 'activity');
        [$formKey, $osmid, $data] = $this->parts($appKey, $input, $migration);
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->flatten($this->repository->create($appKey, $activityKey, $formKey, $osmid, $data, $actorUserId));
            } catch (DuplicateActivityException $error) {
                if (!$generated || $attempt >= 2) throw $error;
                $activityKey = $this->generateKey($appKey);
            }
        }
    }

    public function update(string $activityKey, array $input, bool $migration = false, ?int $actorUserId = null): array
    {
        $appKey = $this->appFrom($input);
        $this->schema->assertKey($activityKey, 'activity');
        if (isset($input['id']) && (string)$input['id'] !== $activityKey) {
            throw new ActivityValidationException(['id' => 'Activity ID cannot be changed.']);
        }
        $existing = $this->repository->find($appKey, $activityKey);
        if ($existing === null) throw new ActivityNotFoundException('Activity not found.');
        [$formKey, $osmid, $data] = $this->parts($appKey, $input, $migration);
        $schemaFields = (array)($this->schema->get($appKey)['fields'] ?? []);
        foreach ((array)$existing['data'] as $field => $value) {
            if (!array_key_exists($field, $schemaFields) && !array_key_exists($field, $data)) {
                $data[$field] = $value;
            }
        }
        $row = $this->repository->update($appKey, $activityKey, $formKey, $osmid, $data, $actorUserId);
        if ($row === null) throw new ActivityNotFoundException('Activity not found.');
        return $this->flatten($row);
    }

    public function delete(string $appKey, string $activityKey): void
    {
        $this->schema->appConfig($appKey);
        $this->schema->assertKey($activityKey, 'activity');
        if (!$this->repository->delete($appKey, $activityKey)) {
            throw new ActivityNotFoundException('Activity not found.');
        }
    }

    public function batch(string $appKey, array $creates, array $updates, array $deletes, ?int $actorUserId = null): array
    {
        $this->schema->appConfig($appKey);
        $total = count($creates) + count($updates) + count($deletes);
        if ($total > 1000) {
            throw new ActivityValidationException(['batch' => 'At most 1000 changes can be saved at once.']);
        }
        if ($this->transactions === null) {
            throw new RuntimeException('Batch saving requires transaction support.');
        }

        foreach ($creates as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new ActivityValidationException(['creates.' . $index => 'Each new activity must be a JSON object.']);
            }
        }
        foreach ($updates as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new ActivityValidationException(['updates.' . $index => 'Each updated activity must be a JSON object.']);
            }
            $key = trim((string)($item['id'] ?? $item['activity_key'] ?? ''));
            if ($key === '') {
                throw new ActivityValidationException(['updates.' . $index . '.id' => 'Activity ID is required.']);
            }
        }
        foreach ($deletes as $index => $key) {
            if (!is_string($key) || trim($key) === '') {
                throw new ActivityValidationException(['deletes.' . $index => 'Activity ID is required.']);
            }
        }

        return $this->transactions->run(function () use ($appKey, $creates, $updates, $deletes, $actorUserId): array {
            $results = ['created' => [], 'updated' => [], 'deleted' => []];
            foreach ($creates as $item) {
                $item['app'] = $appKey;
                $results['created'][] = $this->create($item, false, $actorUserId);
            }
            foreach ($updates as $item) {
                $item['app'] = $appKey;
                $key = trim((string)($item['id'] ?? $item['activity_key']));
                $results['updated'][] = $this->update($key, $item, false, $actorUserId);
            }
            foreach ($deletes as $key) {
                $key = trim($key);
                $this->delete($appKey, $key);
                $results['deleted'][] = $key;
            }
            return $results;
        });
    }

    public function import(string $appKey, array $rows, bool $dryRun = true, ?array $previewFields = null, ?int $actorUserId = null): array
    {
        $this->schema->appConfig($appKey);
        if (count($rows) > 5000) throw new ActivityValidationException(['rows' => 'At most 5000 activities can be imported at once.']);
        $prepared = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ActivityValidationException(['rows.' . $index => 'Each activity must be a JSON object.']);
            }
            $row['app'] = $appKey;
            try {
                $activityKey = trim((string)($row['id'] ?? $row['activity_key'] ?? ''));
                if ($activityKey === '') throw new ActivityValidationException(['id' => 'Activity ID is required for import.']);
                $this->schema->assertKey($activityKey, 'activity');
                if (isset($seen[$activityKey])) throw new ActivityValidationException(['id' => 'Activity ID is duplicated in this import.']);
                $seen[$activityKey] = true;
                [$formKey, $osmid, $data] = $this->parts($appKey, $row, true, $dryRun ? $previewFields : null);
                $prepared[] = compact('activityKey', 'formKey', 'osmid', 'data');
            } catch (ActivityValidationException $error) {
                throw new ActivityValidationException(['rows.' . $index => $error->errors]);
            }
        }

        $created = 0;
        $updated = 0;
        $apply = function () use ($appKey, $prepared, $dryRun, $actorUserId, &$created, &$updated): void {
            foreach ($prepared as $item) {
                $exists = $this->repository->find($appKey, $item['activityKey']) !== null;
                $exists ? $updated++ : $created++;
                if ($dryRun) continue;
                if ($exists) {
                    $this->repository->update($appKey, $item['activityKey'], $item['formKey'], $item['osmid'], $item['data'], $actorUserId);
                } else {
                    $this->repository->create($appKey, $item['activityKey'], $item['formKey'], $item['osmid'], $item['data'], $actorUserId);
                }
            }
        };
        if (!$dryRun && $this->transactions !== null) $this->transactions->run($apply);
        else $apply();
        return ['valid' => count($prepared), 'created' => $created, 'updated' => $updated, 'dry_run' => $dryRun];
    }

    private function appFrom(array $input): string
    {
        $appKey = trim((string)($input['app'] ?? $input['app_key'] ?? ''));
        $this->schema->appConfig($appKey);
        return $appKey;
    }

    private function parts(string $appKey, array $input, bool $migration, ?array $fieldDefinitions = null): array
    {
        $formKey = isset($input['form_key']) && $input['form_key'] !== '' ? trim((string)$input['form_key']) : null;
        if ($formKey !== null) $this->schema->assertKey($formKey, 'form');
        $osmid = trim((string)($input['osmid'] ?? ''));
        $this->validateOsmid($osmid);
        $data = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || isset(self::RESERVED[$key])) continue;
            $data[$key] = $value;
        }
        if (count($data) > 256) throw new ActivityValidationException(['data' => 'At most 256 fields are allowed.']);
        $this->schema->validate($appKey, $data, $migration, $fieldDefinitions);
        return [$formKey, $osmid, $data];
    }

    private function validateOsmid(string $osmid): void
    {
        if (!preg_match('/\A(?:node|way|relation)\/[1-9][0-9]{0,18}\z/', $osmid)) {
            throw new ActivityValidationException(['osmid' => 'OSM ID must look like node/123, way/456, or relation/789.']);
        }
    }

    private function generateKey(string $appKey): string
    {
        $config = $this->schema->appConfig($appKey);
        $prefix = trim((string)($config['id_prefix'] ?? $appKey));
        $this->schema->assertKey($prefix, 'activity');
        return $prefix . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    }

    private function flatten(array $row): array
    {
        $flat = [
            'id' => (string)$row['activity_key'],
            'osmid' => (string)$row['osmid'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
        if ($row['form_key'] !== null && $row['form_key'] !== '') $flat['form_key'] = (string)$row['form_key'];
        foreach ((array)$row['data'] as $key => $value) {
            if (!isset(self::RESERVED[$key])) $flat[$key] = $value;
        }
        return $flat;
    }
}

final class ActivityNotFoundException extends RuntimeException
{
}
