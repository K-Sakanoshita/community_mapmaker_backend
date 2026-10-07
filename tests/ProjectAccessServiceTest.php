<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\ProjectAccessDeniedException;
use CommunityMapMaker\Auth\ProjectAccessRepositoryInterface;
use CommunityMapMaker\Auth\ProjectAccessService;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ProjectAccessService.php';

final class MemoryProjectAccess implements ProjectAccessRepositoryInterface
{
    public function __construct(private array $roles)
    {
    }

    public function rolesForUser(int $userId): array
    {
        return $this->roles[$userId] ?? [];
    }

    public function roleForUser(int $userId, string $appKey): ?string
    {
        return $this->roles[$userId][$appKey] ?? null;
    }

    public function assignProject(int $userId, int $projectId, string $role): void
    {
        $this->roles[$userId]["created-$projectId"] = $role;
    }
}

function accessAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function accessDenied(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        accessAssert($error instanceof ProjectAccessDeniedException, $message . ': got ' . $error::class);
        return;
    }
    throw new RuntimeException($message . ': access was allowed');
}

$service = new ProjectAccessService(new MemoryProjectAccess([
    10 => ['alpha' => 'editor', 'beta' => 'viewer'],
    11 => ['alpha' => 'project_admin'],
    13 => ['alpha' => 'contributor', 'beta' => 'viewer'],
    14 => ['alpha' => 'editor'],
]));
$projects = [
    ['id' => 1, 'app_key' => 'alpha', 'project_name' => 'Alpha', 'enabled' => true],
    ['id' => 2, 'app_key' => 'beta', 'project_name' => 'Beta', 'enabled' => true],
    ['id' => 3, 'app_key' => 'gamma', 'project_name' => 'Gamma', 'enabled' => false],
];

$adminProjects = $service->visibleProjects(['id' => 1, 'role' => 'admin'], $projects);
accessAssert(count($adminProjects) === 3 && $adminProjects[0]['access_role'] === 'admin', 'Admins must see every project with admin access.');
$userProjects = $service->visibleProjects(['id' => 10, 'role' => 'user'], $projects);
accessAssert(array_column($userProjects, 'app_key') === ['alpha', 'beta'], 'Users must only see assigned projects.');
accessAssert(array_column($userProjects, 'access_role') === ['editor', 'viewer'], 'Assigned project roles must be exposed to the console.');
$ownerProjects = $service->visibleProjects(['id' => 11, 'role' => 'user'], array_merge($projects, [['id' => 4, 'app_key' => 'delta', 'project_name' => 'Delta', 'enabled' => false]]));
accessAssert(array_column($ownerProjects, 'app_key') === ['alpha'], 'Users must see only assigned projects.');
$service->assignCreatedProject(['id' => 11, 'role' => 'user'], ['id' => 4]);
$ownerProjects = $service->visibleProjects(['id' => 11, 'role' => 'user'], array_merge($projects, [['id' => 4, 'app_key' => 'created-4', 'project_name' => 'Delta', 'enabled' => false]]));
accessAssert(array_column($ownerProjects, 'app_key') === ['alpha', 'created-4'], 'Project admins must see assigned projects.');

$service->assertCanWrite(['id' => 1, 'role' => 'admin'], 'gamma');
$service->assertCanWrite(['id' => 10, 'role' => 'user'], 'alpha');
$service->assertCanWrite(['id' => 11, 'role' => 'user'], 'alpha');
$service->assertCanWrite(['id' => 13, 'role' => 'contributor'], 'alpha');
$service->assertCanWrite(['id' => 14, 'role' => 'contributor'], 'alpha');
accessDenied(fn() => $service->assertCanWrite(['id' => 13, 'role' => 'contributor'], 'beta'), 'Contributors must respect viewer assignments.');
accessDenied(fn() => $service->assertCanWrite(['id' => 13, 'role' => 'contributor'], 'gamma'), 'Contributors must respect project assignments.');
accessDenied(fn() => $service->assertCanUseConsole(['id' => 14, 'role' => 'contributor']), 'Contributor accounts must not enter the console even with editor assignments.');
accessDenied(fn() => $service->assertCanUseSpreadsheet(['id' => 13, 'role' => 'user'], 'alpha'), 'Project contributors must not use the spreadsheet.');
accessDenied(fn() => $service->assertCanManage(['id' => 11, 'role' => 'contributor'], 'alpha'), 'Contributor accounts must not manage projects even with project-admin assignments.');
accessAssert(array_column($service->visibleConsoleProjects(['id' => 13, 'role' => 'user'], $projects), 'app_key') === ['beta'], 'Contributor projects must be hidden from the console.');
accessAssert(array_column($service->visibleProjects(['id' => 13, 'role' => 'contributor'], $projects), 'app_key') === ['alpha', 'beta'], 'Apps must still see assigned contributor projects.');
$service->assertCanUseSpreadsheet(['id' => 10, 'role' => 'user'], 'alpha');
$service->assertCanUseSpreadsheet(['id' => 10, 'role' => 'user'], 'beta');
$service->assertCanUseSpreadsheet(['id' => 1, 'role' => 'admin'], 'gamma');
$service->assertCanManage(['id' => 1, 'role' => 'admin'], 'gamma');
$service->assertCanManage(['id' => 11, 'role' => 'user'], 'alpha');
accessDenied(fn() => $service->assertCanManage(['id' => 10, 'role' => 'user'], 'alpha'), 'Editors must not manage project settings.');
accessDenied(fn() => $service->assertCanManage(['id' => 10, 'role' => 'user'], 'gamma'), 'Unassigned users must not manage other projects.');
$service->assignCreatedProject(['id' => 12, 'role' => 'user'], ['id' => 4]);
$service->assertCanManage(['id' => 12, 'role' => 'user'], 'created-4');
$ownedProject = ['app_key' => 'created-4', 'created_by_user_id' => 11];
$service->assertCanDelete(['id' => 1, 'role' => 'admin'], $ownedProject);
$service->assertCanDelete(['id' => 11, 'role' => 'user'], $ownedProject);
accessDenied(fn() => $service->assertCanDelete(['id' => 12, 'role' => 'user'], $ownedProject), 'Assigned project admins must not delete another creator’s project.');
accessDenied(fn() => $service->assertCanDelete(['id' => 11, 'role' => 'user'], ['app_key' => 'alpha', 'created_by_user_id' => null]), 'Legacy projects without a recorded creator must be admin-only for deletion.');
accessDenied(fn() => $service->assertCanDelete(['id' => 10, 'role' => 'user'], ['app_key' => 'alpha', 'created_by_user_id' => 10]), 'Creators without project-admin access must not delete.');
accessDenied(fn() => $service->assertCanWrite(['id' => 10, 'role' => 'user'], 'beta'), 'Viewer assignments must be read-only.');
accessDenied(fn() => $service->assertCanWrite(['id' => 10, 'role' => 'user'], 'gamma'), 'Unassigned projects must reject writes.');
accessDenied(fn() => $service->assertCanWrite(['id' => 12, 'role' => 'user'], 'alpha'), 'Users without assignments must reject writes.');

echo "Project access behavior: ok\n";
