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
]));
$projects = [
    ['id' => 1, 'app_key' => 'alpha', 'project_name' => 'Alpha'],
    ['id' => 2, 'app_key' => 'beta', 'project_name' => 'Beta'],
    ['id' => 3, 'app_key' => 'gamma', 'project_name' => 'Gamma'],
];

$adminProjects = $service->visibleProjects(['id' => 1, 'role' => 'admin'], $projects);
accessAssert(count($adminProjects) === 3 && $adminProjects[0]['access_role'] === 'admin', 'Admins must see every project with admin access.');
$userProjects = $service->visibleProjects(['id' => 10, 'role' => 'user'], $projects);
accessAssert(array_column($userProjects, 'app_key') === ['alpha', 'beta'], 'Users must only see assigned projects.');
accessAssert(array_column($userProjects, 'access_role') === ['editor', 'viewer'], 'Assigned project roles must be exposed to the console.');

$service->assertCanWrite(['id' => 1, 'role' => 'admin'], 'gamma');
$service->assertCanWrite(['id' => 10, 'role' => 'user'], 'alpha');
$service->assertCanWrite(['id' => 11, 'role' => 'user'], 'alpha');
accessDenied(fn() => $service->assertCanWrite(['id' => 10, 'role' => 'user'], 'beta'), 'Viewer assignments must be read-only.');
accessDenied(fn() => $service->assertCanWrite(['id' => 10, 'role' => 'user'], 'gamma'), 'Unassigned projects must reject writes.');
accessDenied(fn() => $service->assertCanWrite(['id' => 12, 'role' => 'user'], 'alpha'), 'Users without assignments must reject writes.');

echo "Project access behavior: ok\n";
