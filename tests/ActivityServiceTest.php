<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityNotFoundException;
use CommunityMapMaker\Activity\ActivityRepositoryInterface;
use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivityService;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\UnknownAppException;
use CommunityMapMaker\Auth\TransactionManagerInterface;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivityService.php';

final class MemoryActivities implements ActivityRepositoryInterface
{
    public array $rows = [];
    private int $nextId = 1;

    public function list(string $appKey, ?string $osmid = null): array
    {
        return array_values(array_filter($this->rows, fn(array $row): bool =>
            $row['app_key'] === $appKey && ($osmid === null || $row['osmid'] === $osmid)
        ));
    }

    public function find(string $appKey, string $activityKey): ?array
    {
        return $this->rows[$appKey . "\0" . $activityKey] ?? null;
    }

    public function findForImport(string $appKey, string $activityKey): ?array
    {
        return $this->find($appKey, $activityKey);
    }

    public function restoreForImport(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array
    {
        return $this->update($appKey, $activityKey, $formKey, $osmid, $data, $updatedByUserId, $coordinates);
    }

    public function create(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $createdByUserId = null, ?array $coordinates = null): array
    {
        $key = $appKey . "\0" . $activityKey;
        if (isset($this->rows[$key])) throw new RuntimeException('duplicate');
        return $this->rows[$key] = [
            'id' => $this->nextId++, 'app_key' => $appKey, 'activity_key' => $activityKey,
            'form_key' => $formKey, 'osmid' => $osmid, 'data' => $data,
            'latitude' => $coordinates['latitude'] ?? null, 'longitude' => $coordinates['longitude'] ?? null,
            'created_by_user_id' => $createdByUserId, 'updated_by_user_id' => $createdByUserId,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function update(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array
    {
        $key = $appKey . "\0" . $activityKey;
        if (!isset($this->rows[$key])) return null;
        $this->rows[$key]['form_key'] = $formKey;
        $this->rows[$key]['osmid'] = $osmid;
        $this->rows[$key]['data'] = $data;
        if ($coordinates !== null) $this->rows[$key] = array_replace($this->rows[$key], $coordinates);
        $this->rows[$key]['updated_by_user_id'] = $updatedByUserId;
        $this->rows[$key]['updated_at'] = gmdate('Y-m-d H:i:s');
        return $this->rows[$key];
    }

    public function delete(string $appKey, string $activityKey): bool
    {
        $key = $appKey . "\0" . $activityKey;
        if (!isset($this->rows[$key])) return false;
        unset($this->rows[$key]);
        return true;
    }
}

final class MemoryActivityTransactions implements TransactionManagerInterface
{
    public int $runs = 0;

    public function run(callable $callback): mixed
    {
        $this->runs++;
        return $callback();
    }
}

function activityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function activityThrows(callable $callback, string $class, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        activityAssert($error instanceof $class, $message . ': got ' . $error::class);
        return;
    }
    throw new RuntimeException($message . ': no exception');
}

$schema = new ActivitySchema([
    'playgrounds' => [
        'id_prefix' => 'Playgrounds',
        'schema' => ['fields' => [
            'actdate' => ['type' => 'date', 'required' => true],
            'score' => ['type' => 'select', 'options' => ['1', '2', '3', '4', '5']],
            'body' => ['type' => 'textarea', 'maxLength' => 100],
        ]],
    ],
    'shopping-street' => [
        'id_prefix' => 'Shops',
        'schema' => ['fields' => [
            'genre' => ['type' => 'text', 'required' => true],
        ]],
    ],
]);
$repository = new MemoryActivities();
$transactions = new MemoryActivityTransactions();
$service = new ActivityService($repository, $schema, $transactions);

$created = $service->create([
    'app' => 'playgrounds', 'id' => 'Playgrounds/0001', 'form_key' => 'review', 'osmid' => 'way/123',
    'actdate' => '2026-09-03', 'score' => '5', 'body' => '木陰が多い',
    'future_field' => ['JSON', 'value'],
], false, 42);
activityAssert($created['id'] === 'Playgrounds/0001', 'Public ID must be preserved.');
activityAssert($created['future_field'] === ['JSON', 'value'], 'Unknown fields must survive as JSON without ALTER TABLE.');
activityAssert(!isset($created['app_key']) && !isset($created['data_json']), 'Storage details must not leak into flat output.');
activityAssert(isset($created['created_at'], $created['updated_at']), 'Read-only timestamps must be included in admin output.');
activityAssert($repository->rows["playgrounds\0Playgrounds/0001"]['created_by_user_id'] === 42, 'Authenticated activity creation must record the actor.');

$shop = $service->create([
    'app' => 'shopping-street', 'id' => 'Playgrounds/0001', 'osmid' => 'node/999', 'genre' => 'bakery',
]);
activityAssert($shop['id'] === $created['id'], 'The same public ID may exist in another app.');
activityAssert(count($service->list('playgrounds')) === 1, 'App filtering must exclude other apps.');
activityAssert(count($service->list('shopping-street')) === 1, 'Each app must have an independent result set.');
activityAssert(count($service->list('playgrounds', 'way/123')) === 1, 'OSM ID filtering must work.');
activityAssert($service->list('playgrounds', 'way/999') === [], 'OSM ID filtering must not return unrelated rows.');
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['body'] === '木陰が多い', 'Single Activity reads must return the requested flat object.');

$updated = $service->update('Playgrounds/0001', [
    'app' => 'playgrounds', 'id' => 'Playgrounds/0001', 'osmid' => 'relation/456',
    'actdate' => '2026-09-02', 'score' => '4', 'body' => '更新済み',
], false, 43);
activityAssert($updated['osmid'] === 'relation/456' && $updated['score'] === '4', 'Update must replace common and JSON values.');
activityAssert($repository->rows["playgrounds\0Playgrounds/0001"]['updated_by_user_id'] === 43, 'Authenticated activity updates must record the actor.');

$generated = $service->create([
    'app' => 'playgrounds', 'osmid' => 'node/1', 'actdate' => '2026-09-03',
]);
activityAssert((bool)preg_match('/\APlaygrounds\/\d{14}-[a-f0-9]{12}\z/', $generated['id']), 'Server-generated IDs must be collision-resistant.');

activityThrows(fn() => $service->create([
    'app' => 'playgrounds', 'osmid' => 'way/1', 'actdate' => '2026-02-30',
]), ActivityValidationException::class, 'Invalid dates must be rejected.');
activityThrows(fn() => $service->create([
    'app' => 'playgrounds', 'osmid' => 'way/1', 'actdate' => '2026-09-03', 'score' => '10',
]), ActivityValidationException::class, 'Unknown select values must be rejected.');
$schema->validate('playgrounds', ['photo' => 'File:Park.jpg'], false, ['photo' => ['type' => 'wikimedia']]);
activityThrows(fn() => $schema->validate('playgrounds', ['photo' => 'not an image reference'], false, ['photo' => ['type' => 'wikimedia']]), ActivityValidationException::class, 'Malformed Wikimedia references must be rejected.');
activityThrows(fn() => $service->create([
    'app' => 'unknown', 'osmid' => 'way/1', 'actdate' => '2026-09-03',
]), UnknownAppException::class, 'Unconfigured apps must be rejected.');
activityThrows(fn() => $service->create([
    'app' => 'playgrounds', 'osmid' => '123', 'actdate' => '2026-09-03',
]), ActivityValidationException::class, 'Malformed OSM IDs must be rejected.');

$rows = [
    ['id' => 'Playgrounds/0001', 'osmid' => 'way/123', 'actdate' => '2020-01-02', 'legacy_column' => 'kept'],
    ['id' => 'Playgrounds/0099', 'osmid' => 'node/99', 'actdate' => '2026-09-01', 'legacy_column' => 'kept'],
];
$dryRun = $service->import('playgrounds', $rows, true);
activityAssert($dryRun === ['valid' => 2, 'created' => 1, 'updated' => 1, 'dry_run' => true], 'Dry-run must report create/update counts.');
$candidateFields = $schema->get('playgrounds')['fields'];
$candidateFields['score']['options'][] = '6';
$candidatePreview = $service->import('playgrounds', [[
    'id' => 'Playgrounds/0101', 'osmid' => 'way/101', 'actdate' => '2026-09-03', 'score' => '6',
]], true, $candidateFields);
activityAssert($candidatePreview['valid'] === 1, 'CSV dry-run must be able to validate against its schema candidate.');
activityThrows(fn() => $service->import('playgrounds', [[
    'id' => 'Playgrounds/0101', 'osmid' => 'way/101', 'actdate' => '2026-09-03', 'score' => '6',
]], false, $candidateFields), ActivityValidationException::class, 'Applied imports must ignore preview-only schema overrides.');
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['body'] === '更新済み', 'Dry-run must not modify rows.');
$service->update('Playgrounds/0001', [
    'app' => 'playgrounds', 'id' => 'Playgrounds/0001', 'form_key' => 'review', 'osmid' => 'way/123',
    'actdate' => '2020-01-02', 'score' => '4', 'body' => '更新済み',
]);
$partial = $service->import('playgrounds', [[
    'id' => 'Playgrounds/0001', 'osmid' => 'way/123', 'actdate' => '2020-01-02', 'score' => '3',
]], false);
activityAssert($partial['updated'] === 1, 'Existing key must be updated.');
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['body'] === '更新済み', 'Omitted schema column must retain its value.');
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['form_key'] === 'review', 'Omitted form key must retain its value.');
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['future_field'] === ['JSON', 'value'], 'Omitted unknown column must retain its value.');
$service->import('playgrounds', [['id' => 'Playgrounds/0001', 'body' => 'CSV-only change']], false);
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['osmid'] === 'way/123', 'Omitted OSM ID must retain its value.');
activityThrows(fn() => $service->import('playgrounds', [['id' => 'Playgrounds/new', 'body' => 'missing OSM ID']], true), ActivityValidationException::class, 'New rows still require an OSM ID.');
$imported = $service->import('playgrounds', $rows, false);
activityAssert($imported['created'] === 1 && $imported['updated'] === 1, 'Import must upsert by app and public ID.');
activityAssert($service->find('playgrounds', 'Playgrounds/0001')['actdate'] === '2020-01-02', 'Import must update known fields.');
activityAssert($service->find('playgrounds', 'Playgrounds/0099')['legacy_column'] === 'kept', 'Import must preserve unknown legacy fields.');

$legacyUpdated = $service->update('Playgrounds/0099', [
    'app' => 'playgrounds', 'id' => 'Playgrounds/0099', 'osmid' => 'node/100', 'actdate' => '2026-09-02',
]);
activityAssert($legacyUpdated['legacy_column'] === 'kept', 'Editing after a schema column is removed must preserve its JSON value.');

$batch = $service->batch('playgrounds', [
    ['id' => 'Playgrounds/0100', 'osmid' => 'way/100', 'actdate' => '2026-09-03'],
], [
    ['id' => 'Playgrounds/0099', 'osmid' => 'node/101', 'actdate' => '2026-09-01'],
], ['Playgrounds/0100']);
activityAssert(count($batch['created']) === 1 && count($batch['updated']) === 1 && $batch['deleted'] === ['Playgrounds/0100'], 'Batch must apply create, update and delete together.');
activityAssert($transactions->runs === 4, 'Applied import and batch save must each use a transaction.');
activityThrows(fn() => $service->batch('playgrounds', [['bad-list']], [], []), ActivityValidationException::class, 'Batch must reject malformed rows instead of silently skipping them.');

$service->delete('playgrounds', 'Playgrounds/0099');
activityThrows(fn() => $service->find('playgrounds', 'Playgrounds/0099'), ActivityNotFoundException::class, 'Deleted rows must not be found.');

$base = ['app' => 'playgrounds', 'id' => 'coords/1', 'osmid' => 'node/1', 'actdate' => '2026-09-23'];
$plain = $service->create($base);
activityAssert($plain['latitude'] === null && $plain['longitude'] === null, 'Legacy create returns null coordinates.');
$located = $service->update('coords/1', $base + ['latitude' => '34.85123456', 'longitude' => 135.6178901]);
activityAssert($located['latitude'] === 34.8512346 && $located['longitude'] === 135.6178901, 'Coordinates normalize to numeric seven-decimal snapshots.');
activityAssert(!array_key_exists('latitude', $repository->find('playgrounds', 'coords/1')['data']), 'Coordinates must not be stored in custom data.');
$kept = $service->update('coords/1', $base);
activityAssert($kept['latitude'] === $located['latitude'], 'Omitted coordinates must preserve snapshot.');
$service->import('playgrounds', [$base], false);
activityAssert($service->find('playgrounds', 'coords/1')['longitude'] === $located['longitude'], 'Legacy import preserves snapshot.');
$service->import('playgrounds', [$base + ['latitude' => -90, 'longitude' => 180]], true);
activityAssert($service->find('playgrounds', 'coords/1')['longitude'] === $located['longitude'], 'Dry-run does not modify snapshot.');
$service->import('playgrounds', [$base + ['latitude' => -90, 'longitude' => 180]], false);
activityAssert($service->find('playgrounds', 'coords/1')['latitude'] === -90.0, 'Import updates coordinates at range limits.');
$batch = $service->batch('playgrounds', [array_replace($base, ['id' => 'coords/2', 'latitude' => 90, 'longitude' => -180])], [$base], []);
activityAssert($batch['created'][0]['longitude'] === -180.0 && $batch['updated'][0]['latitude'] === -90.0, 'Batch saves and preserves coordinates.');
$cleared = $service->update('coords/1', $base + ['latitude' => null, 'longitude' => null]);
activityAssert($cleared['latitude'] === null && $cleared['longitude'] === null, 'Explicit null pair clears snapshot.');
$zero = $service->update('coords/1', $base + ['latitude' => 0, 'longitude' => 0]);
activityAssert($zero['latitude'] === 0.0 && $zero['longitude'] === 0.0, 'Zero is a valid coordinate.');
foreach ([
    ['latitude' => 1], ['longitude' => null],
    ['latitude' => null, 'longitude' => 1],
    ['latitude' => 90.00000001, 'longitude' => 0],
    ['latitude' => 0, 'longitude' => -180.00000001],
    ['latitude' => true, 'longitude' => 0],
    ['latitude' => [], 'longitude' => 0],
    ['latitude' => '', 'longitude' => 0],
    ['latitude' => 'abc', 'longitude' => 0],
    ['latitude' => INF, 'longitude' => 0],
    ['latitude' => 0, 'longitude' => NAN],
] as $invalid) {
    activityThrows(fn() => $service->update('coords/1', $base + $invalid), ActivityValidationException::class, 'Invalid coordinates must be rejected.');
    activityThrows(fn() => $service->import('playgrounds', [$base + $invalid], true), ActivityValidationException::class, 'Dry-run must reject invalid coordinates.');
}
$service->import('playgrounds', [array_replace($base, ['id' => 'coords/import', 'latitude' => 12.3, 'longitude' => 45.6])], false);
activityAssert($service->find('playgrounds', 'coords/import')['latitude'] === 12.3, 'Import creates coordinate metadata.');

echo "ActivityService behavior: ok\n";
