<?php
declare(strict_types=1);
// Connection-local temporary tables only. No existing accounts or memberships are changed.
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/AuthService.php';
require_once dirname(__DIR__) . '/lib/ProjectAccessService.php';
require_once dirname(__DIR__) . '/lib/ProjectService.php';
require_once dirname(__DIR__) . '/lib/ProjectUsers.php';
use CommunityMapMaker\Auth\{ProjectUsers, ProjectUserConflict, ProjectAccessDeniedException, ValidationException, AuditLogRepositoryInterface};
use CommunityMapMaker\Activity\ProjectNotFoundException;
$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TEMPORARY TABLE projects (id BIGINT PRIMARY KEY, app_key VARCHAR(64), created_by_user_id BIGINT NULL, is_deleted TINYINT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE users (id BIGINT PRIMARY KEY, userid VARCHAR(64), status VARCHAR(32), role VARCHAR(32), email VARCHAR(255)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE user_projects (user_id BIGINT, project_id BIGINT, role VARCHAR(32), PRIMARY KEY(user_id,project_id)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO projects VALUES (1,'alpha',1,0),(2,'beta',3,0),(3,'legacy',NULL,0),(4,'deleted',NULL,1)");
$pdo->exec("INSERT INTO users VALUES (1,'owner','active','user','owner@example.test'),(2,'member','active','user','member@example.test'),(3,'outsider','active','user',NULL),(4,'globaladmin','active','admin',NULL),(5,'coadmin','active','user',NULL),(6,'viewer','active','user',NULL)");
$pdo->exec("INSERT INTO user_projects VALUES (1,1,'project_admin'),(2,1,'editor'),(2,2,'contributor'),(3,2,'project_admin'),(4,1,'viewer'),(5,1,'project_admin'),(5,3,'project_admin'),(6,1,'viewer')");
$audit = new class implements AuditLogRepositoryInterface {
    public bool $fail = false; public array $rows = [];
    public function record(int $adminUserId, string $action, string $targetType, ?int $targetId, array $detail = []): void {
        if ($this->fail) throw new RuntimeException('audit failure');
        $this->rows[] = [$adminUserId, $action, $targetId, $detail];
    }
    public function list(int $page, int $perPage): array { return []; }
};
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function(callable $fn, string $class) use ($check): void {
    try { $fn(); } catch (Throwable $error) { $check($error instanceof $class, 'Unexpected '.$error::class.': '.$error->getMessage()); return; }
    throw new RuntimeException('Expected '.$class);
};
$service = new ProjectUsers($pdo, $audit);
$list = $service->execute(1,'alpha','GET',[]);
$check(count($list['items'])===5 && !in_array('outsider',array_column($list['items'],'userid'),true),'List contains only this project');
foreach ($list['items'] as $row) $check(array_keys($row)===['id','userid','project_role','is_global_admin'],'Project list must not expose emails or global account controls');
$check($service->execute(1,'alpha','GET',['search'=>'member'])['pagination']['total']===1,'Project search works');
$check(count($service->execute(1,'alpha','GET',['per_page'=>2])['items'])===2,'Project list is paginated');
$check(count($service->execute(4,'beta','GET',[])['items'])===2,'Global admin can manage any project');
foreach ([2,3,6,999] as $id) $reject(fn()=> $service->execute($id,'alpha','GET',[]),ProjectAccessDeniedException::class);
$reject(fn()=> $service->execute(1,'deleted','GET',[]),ProjectNotFoundException::class);
$reject(fn()=> $service->execute(1,'alpha','PUT',['id'=>3,'role'=>'banned']),ValidationException::class);
$reject(fn()=> $service->execute(1,'alpha','PUT',['id'=>2,'role'=>'admin']),ValidationException::class);
foreach (['status','email','password','projects'] as $key) $reject(fn()=> $service->execute(1,'alpha','PUT',['id'=>2,'role'=>'banned',$key=>'injected']),ValidationException::class);
$reject(fn()=> $service->execute(1,'alpha','PUT',['id'=>4,'role'=>'banned']),ValidationException::class);
$reject(fn()=> $service->execute(5,'alpha','PUT',['id'=>1,'role'=>'banned']),ProjectUserConflict::class);
$reject(fn()=> $service->execute(4,'legacy','PUT',['id'=>5,'role'=>null]),ProjectUserConflict::class);
$service->execute(1,'alpha','PUT',['id'=>2,'role'=>'banned']);
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2 AND project_id=1')->fetchColumn()==='banned','Project ban saved');
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2 AND project_id=2')->fetchColumn()==='contributor','Other project membership unchanged');
$check($pdo->query('SELECT role FROM users WHERE id=2')->fetchColumn()==='user','Account role unchanged');
$check(in_array('banned',array_column($service->execute(1,'alpha','GET',[])['items'],'project_role'),true),'Banned users remain manageable');
$service->execute(1,'alpha','PUT',['id'=>2,'role'=>'contributor']);
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2 AND project_id=1')->fetchColumn()==='contributor','Ban lifted by restoring role');
$audit->fail=true;
$reject(fn()=> $service->execute(1,'alpha','PUT',['id'=>2,'role'=>'banned']),RuntimeException::class);
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2 AND project_id=1')->fetchColumn()==='contributor','Audit failure rolls back changes');
$audit->fail=false;
$service->execute(1,'alpha','PUT',['id'=>2,'role'=>null]);
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2 AND project_id=1')->fetchColumn()===false,'Membership removal works');
$service->execute(1,'alpha','PUT',['id'=>5,'role'=>'banned']);
$reject(fn()=> $service->execute(5,'alpha','GET',[]),ProjectAccessDeniedException::class);
$check(count($service->execute(5,'legacy','GET',[])['items'])===1,'Banning administrator does not revoke another project');
$check($audit->rows[0][1]==='project.user.update' && $audit->rows[0][3]['to']==='banned','Membership changes are audited');
echo "Project users: scope, permissions, privacy, bans, rollback and administrator protection passed.\n";
