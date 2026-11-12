<?php
declare(strict_types=1);

// Local Docker only. Temporary tables plus a uniquely named HTTP fixture, removed in finally.
if (getenv('CMM_LOCAL_PARK_TEST') !== '1') { fwrite(STDERR, "Set CMM_LOCAL_PARK_TEST=1 in the local test web container.\n"); exit(1); }
require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ProjectRepository.php';
require_once dirname(__DIR__) . '/lib/ActivityRepository.php';
require_once dirname(__DIR__) . '/lib/OsmParkResolver.php';

use CommunityMapMaker\Activity\ActivityRepository;
use CommunityMapMaker\Activity\OsmParkResolver;
use CommunityMapMaker\Activity\PageRendering;
use CommunityMapMaker\Activity\ParkNotFoundException;
use CommunityMapMaker\Activity\ParkSourceUnavailableException;
use CommunityMapMaker\Activity\ProjectRepository;

$connect = static fn(): PDO => new PDO('mysql:host=database;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$pdo = $connect();
$ddl = $pdo->query('SHOW CREATE TABLE projects')->fetch()['Create Table'];
$ddl = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $ddl);
$ddl = preg_replace('/,\n\)/', "\n)", $ddl);
$pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
$pdo->exec('ALTER TABLE projects DROP COLUMN page_rendering_json');
$pdo->exec("INSERT INTO projects (app_key, project_name, schema_json, created_at, updated_at) VALUES ('legacy', 'Legacy', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/013_project_page_rendering.sql'));
$repo = new ProjectRepository($pdo);
$check($repo->find('legacy')['page_rendering'] === PageRendering::defaults(), 'Migration preserves old project with disabled defaults');
$settings = PageRendering::sanitize(['enabled' => true]);
$repo->create('new', 'New', [], true, 'https://example.test/', true, null, false, $settings);
$check($repo->list()[0]['page_rendering'] === $settings, 'Create/list round trip');
$repo->update('new', 'Renamed', null);
$check($repo->find('new')['page_rendering'] === $settings, 'Unrelated repository updates preserve settings');
$repo->update('new', null, null, null, null, null, null, PageRendering::defaults());
$check(!$repo->find('new')['page_rendering']['enabled'], 'Settings can be disabled');
$pdo->exec('CREATE TEMPORARY TABLE activities (app_key VARCHAR(64), osmid VARCHAR(64), is_deleted TINYINT, updated_at DATETIME)');
$pdo->exec("INSERT INTO activities VALUES ('new','way/1',0,'2026-10-29 00:00:00'),('new','way/1',1,'2026-10-29 01:00:00'),('other','way/1',0,'2026-10-29 02:00:00')");
$activities = new ActivityRepository($pdo);
$check($activities->publicParkSummary('new', 'way/1') === ['activity_count' => 1, 'updated_at' => '2026-10-29T00:00:00Z'], 'Summary excludes deleted and other-project posts');
$check($activities->publicParkSummary('empty', 'way/1') === ['activity_count' => 0, 'updated_at' => null], 'Summary supports zero posts');
unset($pdo, $repo, $activities);

$pdo = $connect();
$app = 'park-http-' . bin2hex(random_bytes(5));
$id = (string)random_int(800000000000000000, 899999999999999990);
$osmid = 'way/' . $id;
$request = static function (string $path, string $method = 'GET', ?array $body = null, ?string $user = null): array {
    $curl = curl_init('http://127.0.0.1:8080/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($user !== null) curl_setopt($curl, CURLOPT_USERPWD, $user . ':LocalTestPass!');
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $payload = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($payload === false) throw new RuntimeException(curl_error($curl));
    curl_close($curl); return [$status, json_decode($payload, true, 512, JSON_THROW_ON_ERROR)];
};
$cacheDir = sys_get_temp_dir() . '/cmm-parks-' . substr(hash('sha256', 'https://overpass-api.de/api/interpreter'), 0, 12);
$ids = [$osmid, 'way/' . ((int)$id + 1), 'way/' . ((int)$id + 2)];
$source = new OsmParkResolver([], fn() => ['elements' => [['type' => 'way', 'id' => $id, 'tags' => ['leisure' => 'park', 'name' => 'HTTP検証公園'], 'center' => ['lat' => 34.7, 'lon' => 135.5]]]]);
try {
    $source->find($osmid);
    $missing = new OsmParkResolver([], fn() => ['elements' => []]);
    try { $missing->find($ids[1]); } catch (ParkNotFoundException) {}
    $unavailable = new OsmParkResolver([], fn() => throw new RuntimeException('fixture source timeout'));
    try { $unavailable->find($ids[2]); } catch (ParkSourceUnavailableException) {}
    [$status, $created] = $request('projects.php', 'POST', ['app_key' => $app, 'project_name' => 'HTTP park test', 'frontend_url' => 'https://example.test/', 'frontend_public' => true], 'localadmin');
    $check($status === 201 && !$created['page_rendering']['enabled'], 'New projects default disabled');
    $path = 'park-page.php?app=' . $app . '&osmid=' . rawurlencode($osmid);
    $check($request($path)[0] === 404, 'Disabled project API is 404');
    $check($request('projects.php?app=' . $app, 'PUT', ['page_rendering' => ['enabled' => true]], 'localviewer')[0] === 403, 'Viewer cannot change page settings');
    $check($request('projects.php?app=' . $app, 'PUT', ['page_rendering' => ['enabled' => true]], 'localadmin')[0] === 200, 'Administrator can enable rendering');
    [$status, $page] = $request($path);
    $check($status === 200 && $page['name'] === 'HTTP検証公園' && $page['activity_count'] === 0, 'Anonymous GET returns a zero-post park');
    $check($page['canonical_url'] === 'https://example.test/parks/' . $osmid && !isset($page['schema'], $page['updated_by_userid']), 'Canonical and public field boundary');
    $check($request('park-page.php?app=' . $app . '&osmid=' . rawurlencode($ids[1]))[0] === 404, 'Missing OSM park is 404');
    [$status, $failure] = $request('park-page.php?app=' . $app . '&osmid=' . rawurlencode($ids[2]));
    $check($status === 503 && $failure['code'] === 'park_source_unavailable', 'OSM outage is 503');
    $check($request('park-page.php?app[]=' . $app . '&osmid=way%2F1')[0] === 422, 'Array parameter is rejected');
    $check($request('park-page.php?app=' . $app . '&osmid=way%2F0')[0] === 422, 'Invalid OSM ID is 422');
    $check($request('park-page.php?app=' . $app)[0] === 422, 'Missing OSM ID is 422');
    $check($request($path, 'POST')[0] === 405, 'Only GET supported');
    $request('projects.php?app=' . $app, 'PUT', ['frontend_public' => false], 'localadmin');
    $check($request($path)[0] === 404, 'Unpublishing takes effect immediately despite OSM cache');
    $request('projects.php?app=' . $app, 'DELETE', null, 'localadmin');
    $check($request($path)[0] === 404, 'Deleted project is 404');
} finally {
    $statement = $pdo->prepare('DELETE FROM projects WHERE app_key = ?'); $statement->execute([$app]);
    foreach ($ids as $key) {
        foreach (['.json', '.json.lock'] as $suffix) { $file = $cacheDir . '/' . hash('sha256', $key) . $suffix; if (is_file($file)) unlink($file); }
    }
}
echo "Park page DB/HTTP: migration 013, legacy defaults, persistence, summaries, manager/viewer permissions, 200/404/422/405/503 and publication changes passed.\n";
