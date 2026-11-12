<?php
declare(strict_types=1);
// Connection-local temporary tables only; real users, projects and posts are untouched.
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ProjectService.php';
require_once dirname(__DIR__) . '/lib/MyProjects.php';
use CommunityMapMaker\Auth\{MyProjects, MyProjectsDenied, AuditLogRepositoryInterface};
use CommunityMapMaker\Activity\ProjectNotFoundException;
$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TEMPORARY TABLE users (id BIGINT PRIMARY KEY, status VARCHAR(32), role VARCHAR(32)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE projects (id BIGINT PRIMARY KEY, app_key VARCHAR(64), project_name VARCHAR(255), enabled TINYINT, frontend_public TINYINT, frontend_url VARCHAR(255), created_by_user_id BIGINT NULL, is_deleted TINYINT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE user_projects (user_id BIGINT, project_id BIGINT, role VARCHAR(32), PRIMARY KEY(user_id,project_id)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO users VALUES (1,'active','user'),(2,'active','user'),(3,'disabled','user'),(4,'active','admin'),(5,'active','contributor')");
$pdo->exec("INSERT INTO projects VALUES (1,'public','Public',1,1,'https://example.test/map',2,0),(2,'private','Private',1,0,NULL,2,0),(3,'banned','Banned',1,1,NULL,2,0),(4,'created','Created',1,1,NULL,1,0),(5,'last-admin','Last admin',1,0,NULL,NULL,0),(6,'disabled','Disabled',0,1,NULL,2,0),(7,'deleted','Deleted',1,1,NULL,NULL,1),(8,'other','Other',1,1,NULL,2,0)");
$pdo->exec("INSERT INTO user_projects VALUES (1,1,'contributor'),(2,1,'project_admin'),(1,2,'viewer'),(1,3,'banned'),(1,4,'project_admin'),(2,4,'project_admin'),(1,5,'project_admin'),(3,5,'project_admin'),(5,5,'project_admin'),(1,6,'editor'),(1,7,'editor'),(2,8,'project_admin')");
$audit = new class implements AuditLogRepositoryInterface {
    public array $rows=[]; public bool $fail=false;
    public function record(int $adminUserId, string $action, string $targetType, ?int $targetId, array $detail=[]): void {
        if ($this->fail) throw new RuntimeException('audit failure');
        $this->rows[]=[$adminUserId,$action,$targetId,$detail];
    }
    public function list(int $page, int $perPage): array { return []; }
};
$check=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$reject=static function(callable $fn,string $reason) use($check):void {
    try { $fn(); } catch (MyProjectsDenied $error) { $check($error->getMessage()===$reason,'Unexpected rejection: '.$error->getMessage()); return; }
    throw new RuntimeException('Expected '.$reason);
};
$service=new MyProjects($pdo,$audit);
$check($service->list(4)['items']===[] && $service->list(4)['pagination']['total']===0,'No memberships yields an empty personal list');
$list=$service->list(1);
$keys=array_column($list['items'],'app_key');
$check(count($keys)===5 && !in_array('banned',$keys,true) && !in_array('deleted',$keys,true) && !in_array('other',$keys,true),'Own memberships include private/disabled and exclude bans, deleted and unrelated projects');
$byKey=array_column($list['items'],null,'app_key');
$check($byKey['public']['can_leave'] && $byKey['private']['can_leave'] && $byKey['disabled']['can_leave'],'Ordinary participation can be cancelled including private/disabled projects');
$check($byKey['created']['leave_reason']==='project_creator_required' && !$byKey['created']['can_leave'],'Creator protection shown');
$check($byKey['last-admin']['leave_reason']==='last_project_administrator','Disabled and legacy-contributor administrators do not count as replacements');
foreach ($byKey as $key=>$item) $check($service->leaveStatus(1,$key)===['can_leave'=>$item['can_leave'],'leave_reason'=>$item['leave_reason']],'Detail and my-page eligibility must match');
$check(!$service->leaveStatus(1,'banned')['can_leave'] && !$service->leaveStatus(1,'other')['can_leave'] && !$service->leaveStatus(1,'deleted')['can_leave'],'No leave action for banned, unassigned or deleted projects');
$check(count($service->list(1,1,2)['items'])===2 && $service->list(1,99,2)['pagination']['page']===3,'Pagination is bounded and clamped after removals');
$check(in_array('other',array_column($service->list(2)['items'],'app_key'),true),'Another actor sees their own memberships');
$reject(fn()=> $service->list(3),'authentication_required');
$reject(fn()=> $service->list(999),'authentication_required');
$reject(fn()=> $service->leave(3,'public'),'authentication_required');
$reject(fn()=> $service->leave(1,'banned'),'project_participation_banned');
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=1 AND project_id=3')->fetchColumn()==='banned','Cannot erase own ban');
$reject(fn()=> $service->leave(1,'created'),'project_creator_required');
$reject(fn()=> $service->leave(1,'last-admin'),'last_project_administrator');
$audit->fail=true;
try { $service->leave(1,'public'); throw new LogicException('Expected audit failure'); } catch (RuntimeException $error) { $check($error->getMessage()==='audit failure','Expected audit failure'); }
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=1 AND project_id=1')->fetchColumn()==='contributor','Audit failure rolls back leave');
$audit->fail=false;
$service->leave(1,'public');
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=1 AND project_id=1')->fetchColumn()===false,'Self membership removed');
$check(!$service->leaveStatus(1,'public')['can_leave'],'Detail reflects completed leave immediately');
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=2 AND project_id=1')->fetchColumn()==='project_admin','Other member unchanged');
$check($pdo->query('SELECT role FROM user_projects WHERE user_id=1 AND project_id=2')->fetchColumn()==='viewer','Other project unchanged');
$count=count($audit->rows);
try { $service->leave(1,'public'); throw new RuntimeException('Unassigned project accepted'); } catch (ProjectNotFoundException) {}
$check(count($audit->rows)===$count,'Repeat leave does not create another audit or affect other members');
$service->leave(1,'private'); $service->leave(1,'disabled');
$pdo->exec("INSERT INTO user_projects VALUES (2,5,'project_admin')");
$check(array_column($service->list(1)['items'],null,'app_key')['last-admin']['can_leave'],'Adding a valid administrator enables leaving');
$check($service->leaveStatus(1,'last-admin')['can_leave'],'Detail eligibility refreshes after adding another administrator');
$service->leave(1,'last-admin');
$check($audit->rows[0][0]===1 && $audit->rows[0][1]==='leave_project' && $audit->rows[0][3]['previous_role']==='contributor','Self leave is audited');
$check(array_column($service->list(1)['items'],'app_key')===['created'],'List reflects completed leaves');
try { $service->leave(1,'deleted'); throw new RuntimeException('Deleted project accepted'); } catch (ProjectNotFoundException) {}
echo "My projects: own list, pagination, self-leave, ban/creator/last-admin protection and rollback passed.\n";
