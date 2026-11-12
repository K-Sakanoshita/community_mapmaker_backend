<?php
declare(strict_types=1);
// Connection-local temporary tables only: never mutate existing project data.
foreach (['Contracts','ActivitySchema','ProjectService','ProjectAccessService','UserRepository','AuditLogRepository','ActivityBulkDeletion'] as $file) require_once dirname(__DIR__).'/lib/'.$file.'.php';
use CommunityMapMaker\Activity\{ActivityBulkDeletion,ActivityBulkDeletionConflict,ActivityValidationException,ProjectNotFoundException};
use CommunityMapMaker\Auth\{AuditLogRepositoryInterface,AuditLogRepository,ProjectAccessDeniedException,UserRepository};
$pdo=new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4','cmm','cmm_local_test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach (['users','projects','activities','user_projects','admin_audit_logs'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch()['Create Table'];
    $ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl); $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$users=new UserRepository($pdo);
foreach (['root'=>['active','admin'],'manager'=>['active','user'],'editor'=>['active','user'],'viewer'=>['active','user'],'outsider'=>['active','user'],'disabled'=>['disabled','admin'],'deleted'=>['deleted','admin'],'banned'=>['active','user']] as $name=>[$status,$role]) $users->create($name,$name,null,null,'unused',$status,$role);
$pdo->exec("INSERT INTO projects (id,app_key,project_name,schema_json,created_by_user_id,created_at,updated_at) VALUES (1,'bulk-test','Test','{}',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),(2,'other','Other','{}',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO user_projects VALUES (2,1,'project_admin',UTC_TIMESTAMP()),(3,1,'editor',UTC_TIMESTAMP()),(4,1,'viewer',UTC_TIMESTAMP()),(8,1,'banned',UTC_TIMESTAMP())");
$insert=$pdo->prepare("INSERT INTO activities (app_key,activity_key,osmid,data_json,is_deleted,deleted_at,created_at,updated_at) VALUES (?,?,?,'{\"name\":\"retained\"}',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
foreach ([['bulk-test','a','node/1',0,null],['bulk-test','b','way/2',0,null],['bulk-test','old','node/3',1,'2020-01-01 00:00:00'],['other','other','node/4',0,null]] as $row) $insert->execute($row);
$audit=new class($pdo) implements AuditLogRepositoryInterface {
    public bool $fail=false;
    public function __construct(private PDO $pdo) {}
    public function record(int $adminUserId,string $action,string $targetType,?int $targetId,array $detail=[]):void { (new AuditLogRepository($this->pdo))->record($adminUserId,$action,$targetType,$targetId,$detail); if($this->fail) throw new RuntimeException('Audit failure'); }
    public function list(int $page,int $perPage):array { return []; }
};
$service=new ActivityBulkDeletion($pdo,$audit);
$check=static function(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); };
$reject=static function(callable $fn,string $type) use($check):void { try {$fn();}catch(Throwable $e){$check($e instanceof $type,'Unexpected '.$e::class.': '.$e->getMessage());return;}throw new RuntimeException('Expected '.$type); };
$before=$pdo->query('SELECT * FROM activities ORDER BY id')->fetchAll();
$preview=$service->execute(2,'bulk-test','GET');
$check($preview['count']===2 && $preview['snapshot_max_id']==='2','Preview covers all active posts only');
$payload=['confirm_app_key'=>'bulk-test','snapshot_max_id'=>$preview['snapshot_max_id'],'expected_count'=>2];
foreach ([3,4,5,6,7,8,999] as $actor) foreach (['GET','POST'] as $method) $reject(fn()=> $service->execute($actor,'bulk-test',$method,$payload),ProjectAccessDeniedException::class);
$reject(fn()=> $service->execute(1,'missing','GET'),ProjectNotFoundException::class);
$reject(fn()=> $service->execute(1,'bulk-test','POST',array_replace($payload,['confirm_app_key'=>'wrong'])),ActivityValidationException::class);
foreach ([[],['snapshot_max_id'=>'0'],['expected_count'=>0],['snapshot_max_id'=>'1 OR 1=1']] as $bad) $reject(fn()=> $service->execute(1,'bulk-test','POST',$bad===[]?[]:array_replace($payload,$bad)),ActivityValidationException::class);
$reject(fn()=> $service->execute(1,'bulk-test','POST',array_replace($payload,['expected_count'=>3])),ActivityBulkDeletionConflict::class);
$check($before===$pdo->query('SELECT * FROM activities ORDER BY id')->fetchAll(),'Previews and denied/conflicting requests do not change posts');
$audit->fail=true;
$reject(fn()=> $service->execute(2,'bulk-test','POST',$payload),RuntimeException::class);
$check($before===$pdo->query('SELECT * FROM activities ORDER BY id')->fetchAll() && (int)$pdo->query('SELECT COUNT(*) FROM admin_audit_logs')->fetchColumn()===0,'Audit failure rolls back every post and the audit event');
$audit->fail=false;
$insert->execute(['bulk-test','later','relation/5',0,null]);
$result=$service->execute(2,'bulk-test','POST',$payload);
$check($result===['status'=>'ok','deleted_count'=>2,'remaining_count'=>1],'New posts after preview are retained');
$after=$pdo->query('SELECT * FROM activities ORDER BY id')->fetchAll();
foreach ([0,1] as $i) $check((int)$after[$i]['is_deleted']===1 && $after[$i]['deleted_at']!==null && (int)$after[$i]['updated_by_user_id']===2 && $after[$i]['data_json']===$before[$i]['data_json'],'Soft deletion retains content and records actor');
$check($before[2]===$after[2] && $before[3]===$after[3],'Previously deleted posts and other projects are untouched');
$check((int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()===2 && (int)$pdo->query('SELECT COUNT(*) FROM user_projects')->fetchColumn()===4,'Project settings and memberships are retained');
$check($pdo->query('SELECT action FROM admin_audit_logs')->fetchColumn()==='activities.soft_delete_all','Deletion is audited');
$reject(fn()=> $service->execute(2,'bulk-test','POST',$payload),ActivityBulkDeletionConflict::class);
$last=$service->execute(1,'bulk-test','GET');
$service->execute(1,'bulk-test','POST',['confirm_app_key'=>'bulk-test','snapshot_max_id'=>$last['snapshot_max_id'],'expected_count'=>1]);
$check($service->execute(1,'bulk-test','GET')['count']===0,'Global administrator can delete the remaining active posts');
$static=['id'=>null,'app_key'=>'static','project_name'=>'Static'];
$check($service->execute(1,'static','GET',[],$static)['count']===0,'Configured static project supports global admin');
$reject(fn()=> $service->execute(2,'static','GET',[],$static),ProjectAccessDeniedException::class);
$pdo->exec('UPDATE projects SET is_deleted=1 WHERE id=1');
$reject(fn()=> $service->execute(1,'bulk-test','GET'),ProjectNotFoundException::class);
echo "Activity bulk deletion: permissions, confirmation, snapshot conflicts, isolation, rollback and retained content passed.\n";
