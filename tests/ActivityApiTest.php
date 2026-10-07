<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';
require_once dirname(__DIR__) . '/lib/Http.php';
require_once dirname(__DIR__) . '/lib/ProjectAccessService.php';

function apiAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$_GET = [];
unset($_SERVER['PATH_INFO']);
apiAssert(ActivityApi::optionalActivityKey() === null, 'A list request must not resolve an Activity ID.');

$_GET['id'] = 'Playgrounds/0001';
apiAssert(ActivityApi::optionalActivityKey() === 'Playgrounds/0001', 'A query-string Activity ID must be resolved.');

$_SERVER['PATH_INFO'] = '/Playgrounds%2F0002';
apiAssert(ActivityApi::optionalActivityKey() === 'Playgrounds/0002', 'A path Activity ID must be decoded and take precedence.');

$_GET = [];
unset($_SERVER['PATH_INFO']);
try {
    ActivityApi::activityKey([]);
    throw new RuntimeException('A write request without an Activity ID must fail.');
} catch (ActivityValidationException $error) {
    apiAssert(isset($error->errors['id']), 'A missing Activity ID must produce an id validation error.');
}

$roles = new class implements \CommunityMapMaker\Auth\ProjectAccessRepositoryInterface {
    public string $role = 'contributor';
    public function rolesForUser(int $userId): array { return ['alpha' => $this->role]; }
    public function roleForUser(int $userId, string $appKey): ?string { return $appKey === 'alpha' ? $this->role : null; }
    public function assignProject(int $userId, int $projectId, string $role): void {}
};
$auth = new class {
    public string $role = 'contributor';
    public function authenticate(string $identity, string $password): array { return ['id' => 1, 'role' => $this->role]; }
};
$container = [
    'auth' => $auth,
    'project_access' => new \CommunityMapMaker\Auth\ProjectAccessService($roles),
    'activity_schema' => new \CommunityMapMaker\Activity\ActivitySchema(['alpha' => ['write_auth_required' => true]]),
];
$_SERVER['PHP_AUTH_USER'] = 'contributor';
$_SERVER['PHP_AUTH_PW'] = 'test-password';
apiAssert(ActivityApi::requireWriteAccess($container, 'alpha')['id'] === 1, 'App contributors must be allowed to post.');
apiAssert(ActivityApi::requireProjectWriteAccess($container, 'alpha')['id'] === 1, 'App contributors must be allowed to edit.');
$_SERVER['HTTP_X_CONSOLE_SESSION'] = '1';
foreach (['contributor', 'user'] as $accountRole) {
    $auth->role = $accountRole;
    try {
        ActivityApi::requireProjectWriteAccess($container, 'alpha');
        throw new LogicException('Contributor console writes must fail.');
    } catch (\CommunityMapMaker\Auth\ProjectAccessDeniedException) {}
}
$roles->role = 'editor';
apiAssert(ActivityApi::requireProjectWriteAccess($container, 'alpha')['id'] === 1, 'Existing editor console writes must still work.');
$auth->role = 'contributor';
$container['activity_schema'] = new \CommunityMapMaker\Activity\ActivitySchema(['alpha' => ['write_auth_required' => false]]);
try {
    ActivityApi::requireWriteAccess($container, 'alpha');
    throw new LogicException('Anonymous-write settings must not bypass console restrictions.');
} catch (\CommunityMapMaker\Auth\ProjectAccessDeniedException) {}
unset($_SERVER['HTTP_X_CONSOLE_SESSION']);
apiAssert(ActivityApi::requireWriteAccess($container, 'alpha') === null, 'Public app posting settings must remain supported.');

echo "Activity API routing and contributor permissions: ok\n";
