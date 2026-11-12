<?php
declare(strict_types=1);
// Connection-local temporary tables only: never change existing project or user data.
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ProjectRepository.php';
$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TEMPORARY TABLE activities (app_key VARCHAR(64), is_deleted TINYINT, created_by_user_id INT, updated_by_user_id INT)');
$pdo->exec('CREATE TEMPORARY TABLE users (id INT, userid VARCHAR(64), email VARCHAR(255))');
$pdo->exec("INSERT INTO users VALUES (1, 'alice', 'private@example.test'), (2, 'bob', 'private2@example.test'), (3, 'deleted-only', NULL)");
$pdo->exec("INSERT INTO activities VALUES ('public', 0, 1, 2), ('public', 0, 1, 1), ('public', 1, 3, 3), ('private', 0, 3, 3), ('public', 0, NULL, NULL)");
$repo = new CommunityMapMaker\Activity\ProjectRepository($pdo);
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$check($repo->withPublicSummaries([]) === [], 'Empty projects');
$result = $repo->withPublicSummaries([['app_key' => 'public'], ['app_key' => 'empty']]);
$check($result[0]['activity_count'] === 3, 'Count active records only, including anonymous records');
$check($result[0]['contributors'] === ['alice', 'bob'], 'Deduplicate creators/editors and exclude deleted records/other projects');
$check($result[1]['activity_count'] === 0 && $result[1]['contributors'] === [], 'Zero counts');
$check(!str_contains(json_encode($result), 'private@example'), 'No email fields');
echo "Public project summaries: ok\n";
