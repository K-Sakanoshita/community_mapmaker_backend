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

    public function create(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $createdByUserId = null): array
    {
        $key = $appKey . "\0" . $activityKey;
        if (isset($this->rows[$key])) throw new RuntimeException('duplicate');
        return $this->rows[$key] = [
            'id' => $this->nextId++, 'app_key' => $appKey, 'activity_key' => $activityKey,
            'form_key' => $formKey, 'osmid' => $osmid, 'data' => $data,
            'created_by_user_id' => $createdByUserId, 'updated_by_user_id' => $createdByUserId,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function update(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null): ?array
    {
        $key = $appKey . "\0" . $activityKey;
        if (!isset($this->rows[$key])) return null;
        $this->rows[$key]['form_key'] = $formKey;
        $this->rows[$key]['osmid'] = $osmid;
        $this->rows[$key]['data'] = $data;
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
activityAssert($transactions->runs === 2, 'Applied import and batch save must each use a transaction.');
activityThrows(fn() => $service->batch('playgrounds', [['bad-list']], [], []), ActivityValidationException::class, 'Batch must reject malformed rows instead of silently skipping them.');

$service->delete('playgrounds', 'Playgrounds/0099');
activityThrows(fn() => $service->find('playgrounds', 'Playgrounds/0099'), ActivityNotFoundException::class, 'Deleted rows must not be found.');

echo "ActivityService behavior: ok\n";
