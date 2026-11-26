<?php
declare(strict_types=1);

// Run against the local Docker database. The test creates a disposable schema.
use CommunityMapMaker\Activity\{ActivityRepository, ActivitySchema, ActivityService};
use CommunityMapMaker\Auth\{Database, ExternalAppException, ExternalApps};

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/Http.php';
require_once dirname(__DIR__) . '/lib/ExternalApps.php';
require_once dirname(__DIR__) . '/lib/ActivityRepository.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivityService.php';

$dsn = getenv('CMM_EXTERNAL_TEST_DSN') ?: 'mysql:host=database;charset=utf8mb4';
$rootPassword = getenv('CMM_EXTERNAL_TEST_ROOT_PASSWORD');
$rootPassword = $rootPassword === false ? 'cmm_local_root' : $rootPassword;
$config = ['dsn' => $dsn, 'user' => 'root', 'password' => $rootPassword];
$root = new Database($config);
$schema = 'cmm_external_test_' . bin2hex(random_bytes(6));
$root->pdo()->exec("CREATE DATABASE `$schema`");
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $action, string $reason) use ($check): void {
    try { $action(); } catch (ExternalAppException $error) {
        $check($error->getMessage() === $reason, 'Unexpected refusal: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $reason);
};

try {
    $config['dsn'] .= ';dbname=' . $schema;
    $db = new Database($config);
    $pdo = $db->pdo();
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/018_external_apps.sql'));
    $pdo->exec("INSERT INTO users (userid,userid_normalized,status,role,created_at,updated_at) VALUES
        ('external-member','external-member','active','user',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
        ('external-other','external-other','active','user',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO osm_identities (user_id,osm_subject) VALUES (1,'10001'),(2,'10002')");
    $pdo->exec("INSERT INTO projects (app_key,project_name,schema_json,created_at,updated_at) VALUES
        ('machi-scrapbook','Scrapbook','{\"fields\":{}}',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
        ('playgrounds','Playgrounds','{\"fields\":{}}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO user_projects (user_id,project_id,role,created_at) VALUES (1,1,'contributor',UTC_TIMESTAMP())");

    $apps = new ExternalApps($pdo);
    $client = 'machi-scrapbook';
    $origin = 'https://machi-scrapbook.openacrossbase.net';
    $redirect = $origin . '/oauth/callback/';
    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $request = ['client_id' => $client, 'redirect_uri' => $redirect, 'state' => 'fixture-state',
        'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => $challenge];
    $makeCode = static function () use ($apps, $db, $request, $check): string {
        $flow = $apps->requestAuthorization($request);
        $url = $db->run(fn() => $apps->consent($flow, 1, true));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
        $check($parameters['state'] === $request['state'], 'State must survive consent.');
        return $parameters['code'];
    };
    $exchange = static fn(string $code, string $proof = '') => $db->run(fn() => $apps->exchange([
        'client_id' => $client, 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        'code' => $code, 'code_verifier' => $proof === '' ? $verifier : $proof,
    ], $origin));
    $reject(fn() => $apps->requestAuthorization(array_replace($request, ['client_id' => 'unregistered'])), 'invalid_client');
    $reject(fn() => $apps->requestAuthorization(array_replace($request, ['redirect_uri' => $redirect . 'wrong'])), 'invalid_redirect_uri');
    $reject(fn() => $db->run(fn() => $apps->consent($apps->requestAuthorization($request), 2, true)), 'project_access_denied');
    $code = $makeCode();
    $reject(fn() => $exchange($code, str_repeat('x', 64)), 'invalid_grant');
    $issued = $exchange($code);
    $reject(fn() => $exchange($code), 'invalid_grant');
    $check($issued['expires_in'] === 900 && $issued['token_type'] === 'Bearer', 'Token expiration and type must be explicit.');
    $_SERVER['HTTP_ORIGIN'] = $origin;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];
    $check($db->run(fn() => $apps->access($issued['access_token'], $origin))['userid'] === 'external-member', 'Token must resolve public userid.');
    $reject(fn() => $db->run(fn() => $apps->access($issued['access_token'], 'https://wrong.example')), 'invalid_token');
    $reject(fn() => $db->run(fn() => $apps->writeAccess('playgrounds', ['batch:create'])), 'insufficient_scope');
    $reject(fn() => $db->run(fn() => $apps->writeAccess($client, ['activities:create'])), 'insufficient_scope');

    $repo = new ActivityRepository($pdo);
    $activity = new ActivityService($repo, new ActivitySchema([$client => ['schema' => ['fields' => []]]]), $db);
    $repo->create($client, 'fixture/other', null, 'node/1', [], 2);
    $own = ['id' => 'fixture/own', 'osmid' => 'node/2'];
    $reject(fn() => $activity->batch($client, [$own], [], ['fixture/other'], externalAuthorization:
        fn() => $apps->writeAccess($client, ['batch:create', 'batch:delete'])), 'activity_owner_required');
    $check($repo->find($client, 'fixture/own') === null, 'An unauthorized change must roll back the whole batch.');
    $check(count($activity->batch($client, [$own], [$own], [], externalAuthorization:
        fn() => $apps->writeAccess($client, ['batch:create', 'batch:update']))['updated']) === 1, 'New own activity can be updated in the same batch.');
    $pdo->exec("UPDATE user_projects SET role='viewer' WHERE user_id=1 AND project_id=1");
    $reject(fn() => $db->run(fn() => $apps->writeAccess($client, ['batch:update'])), 'project_access_denied');
    $pdo->exec("UPDATE user_projects SET role='contributor' WHERE user_id=1 AND project_id=1");
    $expired = $exchange($makeCode())['access_token'];
    $pdo->prepare('UPDATE external_app_keys SET expires_at=UTC_TIMESTAMP() WHERE key_hash=?')
        ->execute([hash('sha256', $expired)]);
    $reject(fn() => $db->run(fn() => $apps->access($expired, $origin)), 'invalid_token');

    $db->run(fn() => $apps->revoke($client, 1));
    $reject(fn() => $db->run(fn() => $apps->writeAccess($client, ['batch:update'])), 'invalid_token');
    $newCode = $makeCode();
    $newToken = $exchange($newCode)['access_token'];
    $db->run(fn() => $apps->stop($client));
    $check(!$apps->allowsOrigin($origin), 'A stopped client must lose its registered origin.');
    $reject(fn() => $db->run(fn() => $apps->access($newToken, $origin)), 'invalid_token');
    $app = array_column($apps->list(), null, 'client_id')[$client];
    $db->run(fn() => $apps->save(array_replace($app, ['enabled' => true]), false));
    $reject(fn() => $db->run(fn() => $apps->access($newToken, $origin)), 'invalid_token');

    echo "External app consent, PKCE, scopes, membership, ownership, rollback and revocation: ok\n";
} finally {
    $root->pdo()->exec("DROP DATABASE `$schema`");
}
