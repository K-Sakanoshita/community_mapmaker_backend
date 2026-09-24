<?php
declare(strict_types=1);

// Run in the local web container. Temporary tables leave existing data untouched.
use CommunityMapMaker\Activity\ActivityRepository;
use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivityService;
use CommunityMapMaker\Activity\ActivityNotFoundException;
use CommunityMapMaker\Activity\DuplicateActivityException;
use CommunityMapMaker\Auth\TransactionManagerInterface;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivityRepository.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivityService.php';

$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=database;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$ddl = $pdo->query('SHOW CREATE TABLE activities')->fetch()['Create Table'];
$ddl = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $ddl);
$ddl = preg_replace('/,\n\)/', "\n)", $ddl);
$pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$throws = static function (callable $fn, string $class) use ($check): void {
    try { $fn(); } catch (Throwable $e) { $check($e instanceof $class, get_class($e)); return; }
    throw new RuntimeException('Expected ' . $class);
};
// Rebuild the pre-migration shape in this connection's temporary table.
$pdo->exec('ALTER TABLE activities DROP COLUMN latitude, DROP COLUMN longitude');
$pdo->exec("INSERT INTO activities (app_key, activity_key, osmid, data_json, created_at, updated_at) VALUES ('migration-test', 'old/1', 'node/1', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/006_activity_coordinates.sql'));
$old = $pdo->query("SELECT latitude, longitude FROM activities WHERE app_key='migration-test'")->fetch();
$check($old === ['latitude' => null, 'longitude' => null], 'Migration must leave existing snapshots null');
$pdo->exec("DELETE FROM activities WHERE app_key='migration-test'");
require_once dirname(__DIR__) . '/lib/AdminUserRepository.php';
$adminRepo = new CommunityMapMaker\Auth\AdminUserRepository($pdo);
$userId = (int)$pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn();
$repo = new ActivityRepository($pdo);
$apps = ['test' => ['schema' => ['fields' => []]], 'other' => ['schema' => ['fields' => []]]];
$schema = new ActivitySchema($apps);
$tx = new class($pdo) implements TransactionManagerInterface {
    public function __construct(private PDO $pdo) {}
    public function run(callable $callback): mixed {
        $this->pdo->beginTransaction();
        try { $result = $callback(); $this->pdo->commit(); return $result; }
        catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
};
$service = new ActivityService($repo, $schema, $tx);
$input = ['app' => 'test', 'id' => 'test/1', 'osmid' => 'node/123', 'body' => 'preserve me', 'is_deleted' => 1, 'deleted_at' => 'fake'];
$created = $service->create($input, false, $userId);
$check(!isset($created['is_deleted'], $created['deleted_at']), 'Internal fields leaked');
$before = $pdo->query("SELECT * FROM activities WHERE app_key='test'")->fetch();
$check((int)$before['is_deleted'] === 0 && $before['deleted_at'] === null, 'Create must remain active');
$repo->create('other', 'test/1', null, 'node/123', ['body' => 'other']);
$check($adminRepo->find($userId)['activity_count'] === 1, 'Active user count');
$service->delete('test', 'test/1');
$user = $adminRepo->find($userId);
$check($user['activity_count'] === 0 && $user['recent_activities'] === [], 'Deleted row in user detail');
$users = $adminRepo->list([], 1, 100);
foreach ($users['items'] as $user) {
    $check($user['activity_count'] === 0, 'Deleted row in user list count');
}
$after = $pdo->query("SELECT * FROM activities WHERE app_key='test'")->fetch();
$check((int)$after['is_deleted'] === 1 && $after['deleted_at'] !== null, 'Row was not retained and flagged');
foreach (['id', 'data_json', 'created_at', 'created_by_user_id', 'updated_by_user_id'] as $field) {
    $check($before[$field] === $after[$field], 'Deleted data changed: ' . $field);
}
$check($repo->list('test') === [] && $repo->list('test', 'node/123') === [], 'Deleted row in list');
$check($repo->find('other', 'test/1') !== null, 'Cross-app delete');
$throws(fn() => $service->find('test', 'test/1'), ActivityNotFoundException::class);
$throws(fn() => $service->update('test/1', $input), ActivityNotFoundException::class);
$throws(fn() => $service->delete('test', 'test/1'), ActivityNotFoundException::class);
$check($repo->update('test', 'test/1', null, 'node/999', []) === null, 'Direct update revived row');
$throws(fn() => $service->create($input), DuplicateActivityException::class);
$check($service->import('test', [$input], true)['updated'] === 1, 'Deleted key must preview as update');
$check($service->import('test', [$input], false)['updated'] === 1, 'Import must restore deleted key');
$restored = $repo->find('test', 'test/1');
$check($restored !== null && $restored['data']['body'] === 'preserve me', 'Restored row must retain data');
$check((int)$pdo->query("SELECT is_deleted FROM activities WHERE app_key='test' AND activity_key='test/1'")->fetchColumn() === 0, 'Import did not clear deletion flag');
$service->delete('test', 'test/1');
$check($repo->searchRows('test') === [] && $repo->searchRows('test', null, ['node/123']) === [], 'Filtered list excludes deleted rows');
$service->create(['app' => 'test', 'id' => 'test/2', 'osmid' => 'node/123']);
$throws(fn() => $service->batch('test', [], [], ['test/2', 'test/missing']), ActivityNotFoundException::class);
$check($repo->find('test', 'test/2') !== null, 'Batch rollback lost active row');
$service->batch('test', [], [], ['test/2']);
$check($repo->find('test', 'test/2') === null, 'Batch failed to delete');
$coordinateInput = ['app' => 'test', 'id' => 'test/coords', 'osmid' => 'way/1'];
$legacy = $service->create($coordinateInput);
$check($legacy['latitude'] === null && $legacy['longitude'] === null, 'Legacy row coordinates');
$located = $service->update('test/coords', $coordinateInput + ['latitude' => 34.8512345, 'longitude' => 135.6178901]);
$check($located['latitude'] === 34.8512345 && $located['longitude'] === 135.6178901, 'Decimal coordinates must round-trip as numbers');
$stored = $pdo->query("SELECT latitude, longitude, data_json FROM activities WHERE activity_key='test/coords'")->fetch();
$check($stored['latitude'] === '34.8512345' && !str_contains($stored['data_json'], 'latitude'), 'Coordinates belong to DECIMAL columns');
$kept = $service->update('test/coords', $coordinateInput);
$check($kept['latitude'] === $located['latitude'], 'SQL update preserves omitted snapshot');
$service->import('test', [$coordinateInput], false);
$check($service->find('test', 'test/coords')['longitude'] === $located['longitude'], 'SQL import preserves omitted snapshot');
$service->batch('test', [], [$coordinateInput + ['latitude' => -90, 'longitude' => 180]], []);
$check($service->list('test', 'way/1')[0]['longitude'] === 180.0, 'SQL batch updates and list exposes coordinates');
$throws(fn() => $service->batch('test', [], [$coordinateInput + ['latitude' => 1, 'longitude' => 2]], ['missing']), ActivityNotFoundException::class);
$check($service->find('test', 'test/coords')['longitude'] === 180.0, 'Failed batch rolls back coordinate updates');
$cleared = $service->update('test/coords', $coordinateInput + ['latitude' => null, 'longitude' => null]);
$check($cleared['latitude'] === null && $cleared['longitude'] === null, 'SQL clears explicit null pair');
$repo->create('test', 'test/in', null, 'way/10', ['body' => 'inside'], null, ['latitude' => 35.0, 'longitude' => 135.0]);
$repo->create('test', 'test/edge', null, 'way/10', ['body' => 'edge'], null, ['latitude' => 35.5, 'longitude' => 135.5]);
$repo->create('test', 'test/out', null, 'way/20', ['body' => 'outside'], null, ['latitude' => 36.0, 'longitude' => 136.0]);
$repo->create('other', 'other/in', null, 'way/10', [], null, ['latitude' => 35.0, 'longitude' => 135.0]);
$repo->create('test', 'test/deleted', null, 'way/10', [], null, ['latitude' => 35.0, 'longitude' => 135.0]);
$repo->delete('test', 'test/deleted');
$bbox = [135.0, 35.0, 135.5, 35.5];
$found = $repo->searchRows('test', $bbox);
$check(array_column($found, 'activity_key') === ['test/edge', 'test/in', 'test/coords'], 'SQL BBOX must include edges and unlocated rows, excluding other apps and deleted rows');
$check(array_column($repo->searchRows('test', $bbox, ['way/10']), 'activity_key') === ['test/edge', 'test/in'], 'SQL BBOX and OSM ID filtering');
$check($repo->searchRows('test', $bbox, ['way/20']) === [] && $repo->searchRows('test', $bbox, []) === [], 'SQL candidate exclusions');
$check(array_column($repo->searchRows('test', null, ['way/20']), 'activity_key') === ['test/out'], 'SQL candidate filtering without BBOX');
$check(count($service->listSelected('test', ['bbox' => '135,35,135.5,35.5'])) === 3, 'BBOX list returns individual activities including unlocated rows');
$priority = $service->listSelected('test', ['osmids' => 'way/10,relation/400', 'bbox' => 'invalid']);
$check(array_column($priority, 'id') === ['test/edge', 'test/in'], 'OSM IDs override BBOX and return flat activities');
$check($service->listSelected('test', ['osmids' => '']) === [], 'Empty OSM ID selection returns no activities');
$throws(fn() => $service->listSelected('test', ['bbox' => '135,35,134,36']), CommunityMapMaker\Activity\ActivityValidationException::class);
$throws(fn() => $service->listSelected('test', ['osmids' => 'invalid']), CommunityMapMaker\Activity\ActivityValidationException::class);

echo "ActivityRepository tests passed.\n";
