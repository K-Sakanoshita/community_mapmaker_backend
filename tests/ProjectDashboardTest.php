<?php
declare(strict_types=1);
// Connection-local temporary tables: no persistent project or Activity changes.
require_once dirname(__DIR__) . '/lib/ProjectService.php';
require_once dirname(__DIR__) . '/lib/ProjectAccessService.php';
require_once dirname(__DIR__) . '/lib/ProjectDashboard.php';
use CommunityMapMaker\Activity\{ProjectDashboard, ProjectNotFoundException};
use CommunityMapMaker\Auth\ProjectAccessDeniedException;
$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TEMPORARY TABLE projects (id INT PRIMARY KEY, app_key VARCHAR(64), project_name VARCHAR(255), enabled INT, is_deleted INT)');
$pdo->exec('CREATE TEMPORARY TABLE user_projects (project_id INT, user_id INT, role VARCHAR(32))');
$pdo->exec('CREATE TEMPORARY TABLE activities (id INT PRIMARY KEY, app_key VARCHAR(64), activity_key VARCHAR(64), form_key VARCHAR(64), osmid VARCHAR(64), data_json TEXT, created_at DATETIME, updated_at DATETIME, is_deleted INT DEFAULT 0)');
$pdo->exec("INSERT INTO projects VALUES (1,'one','Private project',1,0),(2,'other','Other project',1,0),(3,'deleted','Deleted',1,1),(4,'stopped','Stopped',0,0)");
$pdo->exec("INSERT INTO user_projects VALUES (1,1,'viewer'),(1,2,'contributor'),(1,3,'editor'),(1,4,'project_admin'),(1,5,'banned'),(4,1,'viewer')");
$insert=$pdo->prepare("INSERT INTO activities (id, app_key, activity_key, form_key, osmid, data_json, created_at, updated_at, is_deleted) VALUES (?, ?, ?, 'form', 'node/1', ?, ?, ?, ?)");
// Now is Nov 10 12:00 JST; JST midnight is Nov 9 15:00 UTC.
$dates=['2026-11-09 14:59:59','2026-11-09 15:00:00','2026-11-03 15:00:00','2026-11-03 14:59:59','2026-10-11 15:00:00','2026-10-11 14:59:59','2026-11-10 03:00:00','2026-11-10 03:00:00'];
foreach($dates as $i=>$date) $insert->execute([$i+1,'one','post/'.($i+1),'{"body":"<script>unsafe</script>"}',$date,'2026-11-10 04:00:00',0]);
$insert->execute([9,'one','deleted','{}','2026-11-10 01:00:00','2026-11-10 01:00:00',1]);
$insert->execute([10,'other','unrelated','{}','2026-11-10 01:00:00','2026-11-10 01:00:00',0]);
$now=new DateTimeImmutable('2026-11-10T12:00:00+09:00'); $service=new ProjectDashboard($pdo);
$check=static function(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); };
$deny=static function(callable $fn,string $class) use($check):void { try {$fn();} catch(Throwable $e) {$check($e instanceof $class,get_class($e));return;} throw new RuntimeException('Expected rejection'); };
foreach([1,2,3,4] as $id) {
    $result=$service->read(['id'=>$id,'role'=>'user'],'one',1,$now);
    $check($result['counts']===['total'=>8,'today'=>3,'week'=>5,'month'=>7],'Member counts and JST boundaries');
    $check(array_column($result['items'],'id')===['post/8','post/7','post/2','post/1','post/3','post/4','post/5','post/6'],'Created timestamp ordering and stable tie break');
    $check(!isset($result['items'][0]['created_by_user_id']),'No internal user identifiers');
}
foreach([5,6] as $id) $deny(fn()=> $service->read(['id'=>$id,'role'=>'user'],'one'),ProjectAccessDeniedException::class);
$deny(fn()=> $service->read(['id'=>1,'role'=>'user'],'other'),ProjectAccessDeniedException::class);
$deny(fn()=> $service->read(['id'=>1,'role'=>'user'],'deleted'),ProjectNotFoundException::class);
$check($service->read(['id'=>1,'role'=>'user'],'stopped',99,$now)['counts']['total']===0,'Stopped member project and empty counts');
$check($service->read(['id'=>99,'role'=>'admin'],'one',1,$now)['counts']['total']===8,'System administrator access');
for($i=20;$i<46;$i++) $insert->execute([$i,'one','page/'.$i,'{}','2026-01-01 00:00:00','2026-11-10 05:00:00',0]);
$result=$service->read(['id'=>1,'role'=>'user'],'one',999,$now);
$check($result['pagination']['page']===2 && count($result['items'])===14,'Pagination clamps to last page');
$pdo->exec("DELETE FROM user_projects WHERE user_id=1 AND project_id=1");
$deny(fn()=> $service->read(['id'=>1,'role'=>'user'],'one'),ProjectAccessDeniedException::class);
echo "Project dashboard: roles, isolation, deletion, JST boundaries, creation order, pagination and revoked membership passed.\n";
