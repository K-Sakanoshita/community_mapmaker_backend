<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use RuntimeException;

final class ProjectAccessService
{
    private const WRITE_ROLES = ['editor', 'project_admin'];

    public function __construct(private ProjectAccessRepositoryInterface $projects)
    {
    }

    public function visibleProjects(array $user, array $projects): array
    {
        if (($user['role'] ?? 'user') === 'admin') {
            return array_map(static fn(array $project): array => $project + ['access_role' => 'admin'], $projects);
        }

        $roles = $this->projects->rolesForUser((int)$user['id']);
        $visible = [];
        foreach ($projects as $project) {
            $appKey = (string)($project['app_key'] ?? '');
            if (!isset($roles[$appKey])) continue;
            $visible[] = $project + ['access_role' => $roles[$appKey]];
        }
        return $visible;
    }

    public function assignCreatedProject(array $user, array $project): void
    {
        $this->projects->assignProject((int)$user['id'], (int)$project['id'], 'project_admin');
    }

    public function assertCanManage(array $user, string $appKey): void
    {
        if (($user['role'] ?? 'user') === 'admin') return;
        if ($this->projects->roleForUser((int)$user['id'], $appKey) !== 'project_admin') {
            throw new ProjectAccessDeniedException('Project administration is not permitted.');
        }
    }

    public function assertCanDelete(array $user, array $project): void
    {
        if (($user['role'] ?? 'user') === 'admin') return;
        $appKey = (string)($project['app_key'] ?? '');
        $this->assertCanManage($user, $appKey);
        if (($project['created_by_user_id'] ?? null) !== (int)$user['id']) {
            throw new ProjectAccessDeniedException('Only the project creator may delete this project.');
        }
    }

    public function assertCanWrite(array $user, string $appKey): void
    {
        if (($user['role'] ?? 'user') === 'admin') return;
        $role = $this->projects->roleForUser((int)$user['id'], $appKey);
        if ($role === null) throw new ProjectAccessDeniedException('The project is not assigned to this user.');
        if (!in_array($role, self::WRITE_ROLES, true)) {
            throw new ProjectAccessDeniedException('The assigned project is read-only.');
        }
    }
}

final class ProjectAccessDeniedException extends RuntimeException
{
}
