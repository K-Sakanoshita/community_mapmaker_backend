<?php
declare(strict_types=1);
ini_set('session.save_path', sys_get_temp_dir());
// Temporary tables shadow only this connection. No existing accounts or project records are changed.
foreach (['Contracts','AuthService','AdminUserService','AdminUserRepository','UserRepository','TokenRepository','ConsoleSession','AdminUserDeletion','ProjectService','ProjectAccessService','ProjectUsers','AccountDeletion','AuditLogRepository','ProjectAdministrators'] as $file) require_once dirname(__DIR__) . '/lib/' . $file . '.php';
use CommunityMapMaker\Auth\{AdminUserDeletion, AdminUserDeletionDenied, AdminUserDeletedException, AdminUserService, AdminUserRepository, UserRepository, TokenRepository, AuthService, ConsoleSession, LastAdminException, ValidationException, InvalidTokenException, ProjectUsers, AccountDeletion, AccountCredentialsException, Mailer, RateLimiterInterface, TransactionManagerInterface, AuditLogRepositoryInterface};
$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
foreach (['users','projects','activities','user_projects','auth_tokens','admin_audit_logs','auth_rate_limits'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch()['Create Table'];
    $ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl); $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$repo=new UserRepository($pdo); $password='test password for deletion';
foreach (['root'=>['active','admin'],'member'=>['active','user'],'backup'=>['active','admin'],'inactive'=>['disabled','admin'],'manager'=>['active','user']] as $name=>[$status,$role]) {
    $repo->create($name,$name,$name.'@example.test',$name.'@example.test',password_hash($password,PASSWORD_DEFAULT),$status,$role);
}
$pdo->exec("INSERT INTO projects (id,app_key,project_name,schema_json,created_by_user_id,created_at,updated_at) VALUES (1,'deletion-test','Test','{}',2,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO user_projects VALUES (2,1,'editor',UTC_TIMESTAMP()),(5,1,'project_admin',UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO activities (app_key,activity_key,osmid,data_json,created_by_user_id,created_at,updated_at) VALUES ('deletion-test','post','node/1','{}',2,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$tokens=new TokenRepository($pdo); $rawVerify=str_repeat('a',64); $rawReset=str_repeat('b',64);
$tokens->issue(2,'verify_email',hash('sha256',$rawVerify),new DateTimeImmutable('+1 hour'));
$tokens->issue(2,'reset_password',hash('sha256',$rawReset),new DateTimeImmutable('+1 hour'));
$audit=new class($pdo) implements AuditLogRepositoryInterface {
    public function __construct(private PDO $pdo) {}
    public array $rows=[]; public bool $fail=false;
    public function record(int $adminUserId,string $action,string $targetType,?int $targetId,array $detail=[]):void { if ($this->fail) throw new RuntimeException('audit failure'); (new CommunityMapMaker\Auth\AuditLogRepository($this->pdo))->record($adminUserId,$action,$targetType,$targetId,$detail); $this->rows[]=[$adminUserId,$action,$targetId,$detail]; }
    public function list(int $page,int $perPage):array { return []; }
};
$limits=new class implements RateLimiterInterface { public function hit(string $action,string $subject,int $limit,int $windowSeconds):bool { return true; } };
$transactions=new class($pdo) implements TransactionManagerInterface {
    public function __construct(private PDO $pdo) {}
    public function run(callable $callback):mixed { $this->pdo->beginTransaction(); try { $result=$callback(); $this->pdo->commit(); return $result; } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; } }
};
$mailer=new class implements Mailer {
    public function sendVerification(array $user,string $url,DateTimeImmutable $expiresAt):bool { throw new RuntimeException('Unexpected email'); }
    public function sendPasswordReset(array $user,string $url,DateTimeImmutable $expiresAt):bool { throw new RuntimeException('Unexpected email'); }
    public function sendRegistrationNotice(string $recipient,array $user):bool { throw new RuntimeException('Unexpected email'); }
};
$auth=new AuthService($repo,$tokens,$limits,$transactions,$mailer,[]);
$adminRepo=new AdminUserRepository($pdo); $users=new AdminUserService($adminRepo,$auth,$audit,$transactions);
$secret=str_repeat('s',32); $deletion=new AdminUserDeletion($pdo,$audit,$secret);
$check=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$reject=static function(callable $fn,string $class) use($check):void { try { $fn(); } catch (Throwable $error) { $check($error instanceof $class,'Unexpected '.$error::class.': '.$error->getMessage()); return; } throw new RuntimeException('Expected '.$class); };
$check($auth->authenticate('member',$password)!==null,'Target can sign in before deletion');
$_SERVER['SCRIPT_NAME']='/api/console-session.php'; $_SERVER['HTTP_X_CONSOLE_SESSION']='1';
ConsoleSession::establish($repo->findById(2)); $_COOKIE['cmm_console']=session_id();
$check(ConsoleSession::user(['user_repo'=>$repo])!==null,'Target has an existing authenticated session');
foreach ([2,4,5,999] as $actor) $reject(fn()=> $deletion->delete($actor,2,'member'),AdminUserDeletionDenied::class);
$reject(fn()=> $deletion->delete(1,1,'root'),AdminUserDeletionDenied::class);
$reject(fn()=> $deletion->delete(1,2,'wrong'),ValidationException::class);
$audit->fail=true;
$reject(fn()=> $deletion->delete(1,2,'member'),RuntimeException::class);
$check($repo->findById(2)['status']==='active' && (int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE user_id=2 AND used_at IS NULL')->fetchColumn()===2,'Audit failure rolls back status and token invalidation');
$audit->fail=false;
$result=$deletion->delete(1,2,'member');
$check($result['user_deleted'] && $repo->findById(2)['status']==='deleted','Soft deletion persists');
$check($repo->findById(2)['email']==='member@example.test' && password_verify($password,$repo->findById(2)['password_hash']),'Account record is retained');
$check($auth->authenticate('member',$password)===null && ConsoleSession::user(['user_repo'=>$repo])===null,'Basic authentication and existing sessions are revoked');
$check((int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE user_id=2 AND used_at IS NULL')->fetchColumn()===0,'All outstanding tokens are invalidated');
$reject(fn()=> $auth->verifyEmail($rawVerify),InvalidTokenException::class);
$reject(fn()=> $auth->resetPassword(['token'=>$rawReset,'password'=>'new valid password','password_confirmation'=>'new valid password']),InvalidTokenException::class);
$check((int)$pdo->query('SELECT COUNT(*) FROM user_projects WHERE user_id=2')->fetchColumn()===1 && (int)$pdo->query('SELECT created_by_user_id FROM activities')->fetchColumn()===2 && (int)$pdo->query('SELECT created_by_user_id FROM projects')->fetchColumn()===2,'Memberships, posts, ownership and attribution remain');
$check(!in_array(2,array_column($users->list([])['items'],'id'),true),'Default global list excludes deleted users');
$check(array_column($users->list(['status'=>'deleted'])['items'],'id')===[2],'Deleted filter provides read-only visibility');
$check($users->find(2)['status']==='deleted','Deleted details remain readable');
$reject(fn()=> $users->update(2,['status'=>'active'],1),AdminUserDeletedException::class);
$reject(fn()=> $users->setPassword(2,['password'=>'new valid password','password_confirmation'=>'new valid password'],1),AdminUserDeletedException::class);
$reject(fn()=> $users->sendPasswordReset(2,1,'local'),AdminUserDeletedException::class);
$reject(fn()=> $users->resendVerification(2,1,'local'),AdminUserDeletedException::class);
$check($adminRepo->update(2,'active','user')===null && $repo->findById(2)['status']==='deleted','A stale update cannot reactivate the account');
$projectUsers=new ProjectUsers($pdo,$audit);
$check(!in_array(2,array_column($projectUsers->execute(5,'deletion-test','GET',[])['items'],'id'),true),'Project management excludes deleted users');
$reject(fn()=> $projectUsers->execute(5,'deletion-test','PUT',['id'=>2,'role'=>'editor']),ValidationException::class);
$pdo->exec("UPDATE user_projects SET role='project_admin' WHERE user_id=2");
$projectAdmins=new CommunityMapMaker\Auth\ProjectAdministrators($pdo,new CommunityMapMaker\Auth\AuditLogRepository($pdo));
$reject(fn()=> $projectAdmins->execute(5,'deletion-test','DELETE','member'),ValidationException::class);
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2')->fetchColumn()==='project_admin','Project administrator management cannot modify deleted user memberships');
$accountDeletion=new AccountDeletion($pdo,$limits,str_repeat('s',32));
$reject(fn()=> $accountDeletion->delete(['identity'=>'member','password'=>$password,'confirm_userid'=>'member'],'local'),AccountCredentialsException::class);
$count=count($audit->rows); $deletion->delete(1,2,'member');
$check(count($audit->rows)===$count && $audit->rows[0][1]==='user.soft_delete','Deletion is idempotent and audited');
$deletion->delete(1,3,'backup');
$reject(fn()=> $deletion->delete(1,1,'root'),LastAdminException::class);

// Deleted-list lifecycle operations use only connection-local temporary tables.
foreach (['restore','purge'] as $action) {
    foreach ([2,4,5,999] as $actor) $reject(fn()=> $deletion->$action($actor,2,'member'),AdminUserDeletionDenied::class);
    $reject(fn()=> $deletion->$action(1,2,'wrong'),ValidationException::class);
    $reject(fn()=> $deletion->$action(1,999,'missing'),CommunityMapMaker\Auth\AdminUserNotFoundException::class);
    $reject(fn()=> $deletion->$action(1,5,'manager'),AdminUserDeletionDenied::class);
    $reject(fn()=> $deletion->$action(1,1,'root'),AdminUserDeletionDenied::class);
}
$audit->fail=true;
$reject(fn()=> $deletion->restore(1,2,'member'),RuntimeException::class);
$check($repo->findById(2)['status']==='deleted','Failed restoration rolls back');
$audit->fail=false;
$restored=$deletion->restore(1,2,'member');
$check($restored['restored_status']==='active' && $repo->findById(2)['status']==='active','Restoration uses the previous status');
$check($auth->authenticate('member',$password)!==null,'Restored user keeps the same password');
$check(ConsoleSession::user(['user_repo'=>$repo])===null,'Restoration never revives a session issued before deletion, including same-second deletion');
$check((int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE user_id=2 AND used_at IS NULL')->fetchColumn()===0,'Restoration keeps old links invalid');
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2')->fetchColumn()==='project_admin','Restoration preserves membership roles');
$check(in_array(2,array_column($users->list([])['items'],'id'),true) && !in_array(2,array_column($users->list(['status'=>'deleted'])['items'],'id'),true),'Restored user moves from deleted list to normal list');
$deletion->delete(1,2,'member');
$subject=hash_hmac('sha256','identity:member',$secret);
$pdo->exec("INSERT INTO auth_rate_limits (action_key,subject_hash,window_started_at,updated_at) VALUES ('delete_account_identity','$subject',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('delete_account_client','".str_repeat('f',64)."',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$audit->fail=true;
$reject(fn()=> $deletion->purge(1,2,'member'),RuntimeException::class);
$check($repo->findById(2)['status']==='deleted' && (int)$pdo->query('SELECT created_by_user_id FROM activities')->fetchColumn()===2,'Purge audit failure rolls back account and attribution');
$check((int)$pdo->query('SELECT COUNT(*) FROM user_projects WHERE user_id=2')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE user_id=2')->fetchColumn()===2,'Purge failure rolls back memberships and tokens');
$check((int)$pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE target_id=2")->fetchColumn()>0 && (int)$pdo->query('SELECT COUNT(*) FROM auth_rate_limits')->fetchColumn()===2,'Purge failure rolls back audit and identifiable limits');
$audit->fail=false;
$purged=$deletion->purge(1,2,'member');
$check($purged['user_purged'] && $repo->findById(2)===null,'Purge removes the account');
$check((int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE user_id=2')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM user_projects WHERE user_id=2')->fetchColumn()===0,'Purge removes authentication and membership records');
$check((int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn()===1 && $pdo->query('SELECT created_by_user_id FROM activities')->fetchColumn()===null,'Purge retains contributions and clears attribution');
$check((int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()===1 && $pdo->query('SELECT created_by_user_id FROM projects')->fetchColumn()===null,'Purge retains projects and clears ownership');
$events=$pdo->query("SELECT action, detail_json FROM admin_audit_logs WHERE target_type='user' AND target_id=2")->fetchAll();
$check(count($events)===1 && $events[0]['action']==='user.purge' && $events[0]['detail_json']===null,'Only a purge event without personal information remains');
$check((int)$pdo->query("SELECT COUNT(*) FROM auth_rate_limits WHERE subject_hash='$subject'")->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM auth_rate_limits')->fetchColumn()===1,'Purge removes identity limits and retains client limits');
$reject(fn()=> $deletion->restore(1,2,'member'),CommunityMapMaker\Auth\AdminUserNotFoundException::class);
foreach (['pending','disabled'] as $status) {
    $user=$repo->create('state-'.$status,'state-'.$status,$status.'@example.test',$status.'@example.test',password_hash($password,PASSWORD_DEFAULT),$status,'user');
    $id=(int)$user['id']; $deletion->delete(1,$id,$user['userid']);
    $deletion->restore(1,$id,$user['userid']);
    $check($repo->findById($id)['status']===$status,'Restoration does not activate pending or disabled accounts');
}
// Legacy deleted records without an audit event must not bypass email verification.
$user=$repo->create('legacy-deleted','legacy-deleted','legacy@example.test','legacy@example.test',password_hash($password,PASSWORD_DEFAULT),'deleted','user');
$check($deletion->restore(1,(int)$user['id'],'legacy-deleted')['restored_status']==='pending','Legacy unverified users restore pending');
ConsoleSession::destroy();
echo "Admin user soft deletion, restoration and purge: privilege scope, confirmation, sessions/tokens, retained records, read-only listing, last-admin protection and rollback passed.\n";
