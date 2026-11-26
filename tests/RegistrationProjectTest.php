<?php
declare(strict_types=1);

require __DIR__ . '/AuthServiceTest.php';

$registrationProjects = new class implements CommunityMapMaker\Activity\ProjectRepositoryInterface {
    public array $rows = [
        'playgrounds' => ['id' => 11, 'enabled' => true, 'frontend_public' => true, 'recruiting' => true, 'is_deleted' => false],
        'other-map' => ['id' => 12, 'enabled' => true, 'frontend_public' => true, 'recruiting' => true, 'is_deleted' => false],
        'closed' => ['id' => 16, 'enabled' => true, 'frontend_public' => true, 'recruiting' => false, 'is_deleted' => false],
        'private' => ['id' => 13, 'enabled' => true, 'frontend_public' => false, 'is_deleted' => false],
        'disabled' => ['id' => 14, 'enabled' => false, 'frontend_public' => true, 'recruiting' => true, 'is_deleted' => false],
        'deleted' => ['id' => 15, 'enabled' => true, 'frontend_public' => true, 'recruiting' => true, 'is_deleted' => true],
    ];
    public function find(string $appKey): ?array { return $this->rows[$appKey] ?? null; }
    public function list(bool $onlyEnabled = false): array { throw new LogicException(); }
    public function deletedKeys(): array { throw new LogicException(); }
    public function create(string $appKey, string $projectName, array $schema, bool $enabled = true, ?string $frontendUrl = null, bool $frontendPublic = false, ?int $createdByUserId = null, bool $recruiting = false, ?array $pageRendering = null): array { throw new LogicException(); }
    public function update(string $appKey, ?string $projectName, ?array $schema, ?bool $enabled = null, ?string $frontendUrl = null, ?bool $frontendPublic = null, ?bool $recruiting = null, ?array $pageRendering = null): ?array { throw new LogicException(); }
    public function delete(string $appKey): bool { throw new LogicException(); }
};
$memberships = new class implements CommunityMapMaker\Auth\ProjectAccessRepositoryInterface {
    public array $rows = [];
    public function assignProject(int $userId, int $projectId, string $role): void { $this->rows[$userId] = ['project_id' => $projectId, 'role' => $role]; }
    public function rolesForUser(int $userId): array { throw new LogicException(); }
    public function roleForUser(int $userId, string $appKey): ?string { throw new LogicException(); }
};
$projectUsers = new MemoryUsers();
$projectTokens = new MemoryTokens();
$projectMailer = new RecordingMailer();
$projectAuth = new CommunityMapMaker\Auth\AuthService($projectUsers, $projectTokens, new AllowingRateLimiter(), new ImmediateTransactions(), $projectMailer, $config, $registrationProjects, $memberships);
$input = ['userid' => 'project-user', 'email' => 'project@example.test', 'password' => 'TestPass123!', 'password_confirmation' => 'TestPass123!', 'role' => 'admin', 'project_role' => 'project_admin'];
foreach (['playgrounds' => 11, 'other-map' => 12] as $key => $id) {
    $result = $projectAuth->register(array_replace($input, ['userid' => 'user-' . $key, 'email' => $key . '@example.test', 'app_key' => $key]), 'test-client');
    $user = $projectUsers->findByIdentity('user-' . $key);
    assertTrue($memberships->rows[$user['id']] === ['project_id' => $id, 'role' => 'contributor'], 'Registration must assign only the originating project as contributor.');
    assertTrue($user['role'] === 'user' && $user['status'] === 'pending', 'Project registration must create a regular user without accepting the supplied admin role or bypassing verification.');
}
foreach (['closed', 'private', 'disabled', 'deleted', 'missing', ['playgrounds']] as $key) {
    assertThrows(fn() => $projectAuth->register($input + ['app_key' => $key], 'test-client'), CommunityMapMaker\Auth\ValidationException::class, 'Unavailable or malformed project must be rejected.');
    assertTrue(count($projectUsers->rows) === 2 && count($memberships->rows) === 2, 'Rejected project must not leave an account or membership.');
}
$projectAuth->register($input, 'test-client');
assertTrue(count($projectUsers->rows) === 3 && count($memberships->rows) === 2, 'Registration without a project must not assign one.');
$osmInput = ['app_key' => 'playgrounds', 'privacy_acknowledged' => true];
assertTrue($projectAuth->validateOsmRegistration($osmInput) === $osmInput, 'OSM registration must accept an eligible project without user ID, email or password.');
assertTrue($projectAuth->validateOsmRegistration($osmInput + ['userid' => 'supplied']) === $osmInput, 'OSM registration ignores a supplied user ID from an older client.');
assertThrows(fn() => $projectAuth->validateOsmRegistration(array_replace($osmInput, ['privacy_acknowledged' => false])), CommunityMapMaker\Auth\ValidationException::class, 'OSM registration must require acknowledgement.');
foreach (['closed', 'private', 'disabled', 'deleted', 'missing'] as $key) {
    assertThrows(fn() => $projectAuth->validateOsmRegistration(array_replace($osmInput, ['app_key' => $key])), CommunityMapMaker\Auth\ValidationException::class, 'OSM registration must enforce project eligibility.');
}
assertTrue(count($projectUsers->rows) === 3 && count($memberships->rows) === 2, 'Starting OSM registration must wait for authentication before creating an account.');
$emailOnly = $input;
unset($emailOnly['userid']);
$emailOnly['email'] = 'email-only@example.test';
$projectAuth->register($emailOnly, 'test-client');
$emailUser = $projectUsers->findByIdentity($emailOnly['email']);
assertTrue(preg_match('/\Auser-[a-f0-9]{20}\z/', $emailUser['userid']) === 1, 'Email registration generates a separate public ID.');
assertTrue($projectAuth->authenticate('EMAIL-ONLY@example.test', $emailOnly['password']) === null, 'Email sign-in requires verification.');
$projectUsers->verifyEmail((int)$emailUser['id']);
assertTrue($projectAuth->authenticate('EMAIL-ONLY@example.test', $emailOnly['password'])['userid'] === $emailUser['userid'], 'Verified email can sign in without entering the public ID.');
echo "Registration project behavior: ok\n";

require_once dirname(__DIR__) . '/lib/RegistrationProjects.php';
$openProject = ['app_key'=>'open', 'project_name'=>'Open', 'frontend_url'=>'https://example.test/map/', 'enabled'=>true, 'frontend_public'=>true, 'recruiting'=>true, 'is_deleted'=>false];
assertTrue(CommunityMapMaker\Auth\RegistrationProjects::present($openProject) !== null, 'Open recruitment must be selectable during registration.');
assertTrue(CommunityMapMaker\Auth\RegistrationProjects::present(array_replace($openProject, ['recruiting'=>false])) === null, 'Closed recruitment must not be selectable during registration.');
