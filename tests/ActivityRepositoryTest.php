<?php
declare(strict_types=1);

// Run in the local web container. Temporary tables leave existing data untouched.
use CommunityMapMaker\Activity\ActivityRepository;
use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivityService;
use CommunityMapMaker\Activity\ActivitySearchService;
use CommunityMapMaker\Activity\ActivityNotFoundException;
use CommunityMapMaker\Activity\DuplicateActivityException;
use CommunityMapMaker\Auth\TransactionManagerInterface;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivityRepository.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivityService.php';
require_once dirname(__DIR__) . '/lib/ActivitySearchService.php';

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
$search = new ActivitySearchService($repo, $schema, $apps);
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
$throws(fn() => $service->import('test', [$input], false), DuplicateActivityException::class);
$check($search->search('test', [])['pagination']['total'] === 0, 'Search includes deleted row');
$missing = $search->search('test', ['osmids' => ['node/123'], 'research_mode' => 'missing']);
$check($missing['items'][0]['activity_count'] === 0, 'Missing search counts deleted row');
$service->create(['app' => 'test', 'id' => 'test/2', 'osmid' => 'node/123']);
$throws(fn() => $service->batch('test', [], [], ['test/2', 'test/missing']), ActivityNotFoundException::class);
$check($repo->find('test', 'test/2') !== null, 'Batch rollback lost active row');
$service->batch('test', [], [], ['test/2']);
$check($repo->find('test', 'test/2') === null, 'Batch failed to delete');
echo "ActivityRepository tests passed.\n";
