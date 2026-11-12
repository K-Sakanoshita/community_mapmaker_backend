<?php
declare(strict_types=1);

// Reuse the existing in-memory project repository and its service regression checks.
ob_start(); require __DIR__ . '/ProjectServiceTest.php'; ob_end_clean();
require_once dirname(__DIR__) . '/lib/OsmParkResolver.php';
require_once dirname(__DIR__) . '/lib/ParkPageService.php';

use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\OsmParkResolver;
use CommunityMapMaker\Activity\PageRendering;
use CommunityMapMaker\Activity\ParkNotFoundException;
use CommunityMapMaker\Activity\ParkPageService;
use CommunityMapMaker\Activity\ParkSourceUnavailableException;
use CommunityMapMaker\Activity\ProjectNotFoundException;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$throws = static function (callable $fn, string $class) use ($assert): void {
    try { $fn(); } catch (Throwable $error) { $assert($error instanceof $class, 'Expected ' . $class . ', got ' . $error::class); return; }
    throw new RuntimeException('Expected ' . $class);
};
foreach ([null, true, ['enabled' => 'true'], ['other' => 1], ['path_template' => '//host/{osm_type}/{osm_id}'],
    ['path_template' => '/a/../{osm_type}/{osm_id}'], ['path_template' => '/parks/{osm_id}'],
    ['path_template' => '/parks/{osm_type}/{osm_id}?x=1'], ['title_template' => '{unknown} {name}'],
    ['default_image_url' => 'javascript:alert(1)'], ['default_image_url' => 'https://user:pass@example.test/a.png']] as $invalid) {
    $throws(fn() => PageRendering::sanitize($invalid), ActivityValidationException::class);
}
$assert(PageRendering::sanitize(['default_description' => "line1\nline2"])['default_description'] === 'line1 line2', 'Description supports textarea input');
// A static project keeps its page settings when first saved into the database.
$staticRepo = new MemoryProjects();
$staticSchema = new \CommunityMapMaker\Activity\ActivitySchema(['static-parks' => ['schema' => ['fields' => []], 'frontend_url' => 'https://static.example.test/', 'frontend_public' => true, 'page_rendering' => ['enabled' => true]]], $staticRepo);
$staticService = new \CommunityMapMaker\Activity\ProjectService($staticRepo, $staticSchema);
$assert($staticService->find('static-parks')['page_rendering']['enabled'], 'Static settings available');
$assert($staticService->update('static-parks', ['project_name' => 'Static map'])['page_rendering']['enabled'], 'First DB save preserves static settings');

$settings = ['enabled' => true, 'path_template' => '/map/parks/{osm_type}/{osm_id}', 'title_template' => '{name} - {project_name}'];
$created = $service->create(['app_key' => 'park-test', 'project_name' => '公園マップ', 'frontend_url' => 'https://example.test/map/', 'frontend_public' => true, 'page_rendering' => $settings]);
$assert($created['page_rendering']['enabled'], 'Project create stores settings');
$throws(fn() => $service->update('park-test', ['page_rendering' => null]), ActivityValidationException::class);
$service->update('park-test', ['project_name' => '新しい公園マップ']);
$assert($service->find('park-test')['page_rendering']['path_template'] === $settings['path_template'], 'Unrelated updates preserve settings');
$service->update('park-test', ['page_rendering' => ['default_image_url' => 'https://example.test/ogp.png']]);
$assert($service->find('park-test')['page_rendering']['enabled'], 'Partial settings update preserves enabled');
$throws(fn() => $service->update('park-test', ['frontend_url' => 'https://example.test/#park']), ActivityValidationException::class);
$throws(fn() => $service->update('park-test', ['frontend_url' => 'https://example.test/?park=1']), ActivityValidationException::class);

$dir = sys_get_temp_dir() . '/cmm-park-test-' . bin2hex(random_bytes(6));
$calls = 0;
$resolver = new OsmParkResolver(['cache_dir' => $dir], function ($query) use (&$calls): array {
    $calls++;
    preg_match('/;(node|way|relation)\(([0-9]+)\)/', $query, $match);
    return ['elements' => [['type' => $match[1], 'id' => $match[2], 'lat' => 0, 'lon' => 0,
        'center' => ['lat' => 34.7, 'lon' => 135.5], 'tags' => ['leisure' => 'park', 'name' => '公園 <script>alert(1)</script>', 'addr:city' => '大阪市'],
        'timestamp' => '2026-10-29T00:00:00Z', 'user' => 'must not leak', 'uid' => 123]]];
});
try {
    $summaryCalls = 0;
    $pages = new ParkPageService($service, $resolver, function ($app, $osmid) use (&$summaryCalls): array {
        $summaryCalls++; return ['activity_count' => 0, 'updated_at' => null];
    });
    foreach (['node/1', 'way/2', 'relation/3'] as $osmid) {
        $page = $pages->find('park-test', $osmid);
        $assert($page['canonical_url'] === 'https://example.test/map/parks/' . $osmid, 'Canonical uses root-relative template');
        $assert($page['activity_count'] === 0 && $page['name'] !== '', 'Zero-post parks still resolve');
        $assert($page['title'] === $page['name'] . ' - 新しい公園マップ', 'Template uses current project title');
        $assert(!isset($page['user'], $page['uid'], $page['tags']), 'Only public allowlisted fields returned');
        $assert($page['latitude'] === ($osmid === 'node/1' ? 0.0 : 34.7), 'Zero/node and way/relation centers supported');
    }
    $pages->find('park-test', 'way/2');
    $assert($calls === 3, 'Positive OSM results cached');
    $service->update('park-test', ['frontend_public' => false]);
    $throws(fn() => $pages->find('park-test', 'way/2'), ProjectNotFoundException::class);
    $assert($calls === 3, 'Private project rejected before OSM lookup despite cache');
    $service->update('park-test', ['frontend_public' => true, 'page_rendering' => ['enabled' => false]]);
    $throws(fn() => $pages->find('park-test', 'way/2'), ProjectNotFoundException::class);
    $service->update('park-test', ['page_rendering' => ['enabled' => true]]);
    $service->delete('park-test');
    $throws(fn() => $pages->find('park-test', 'way/2'), ProjectNotFoundException::class);
    foreach (['way/0', 'node/01', 'way/1);out;', 'relation/1/extra', 'way/12345678901234567890'] as $bad) {
        $throws(fn() => $resolver->find($bad), ActivityValidationException::class);
    }
    $missingCalls = 0;
    $missing = new OsmParkResolver(['cache_dir' => $dir], function () use (&$missingCalls): array { $missingCalls++; return ['elements' => []]; });
    $throws(fn() => $missing->find('way/4'), ParkNotFoundException::class);
    $throws(fn() => $missing->find('way/4'), ParkNotFoundException::class);
    $assert($missingCalls === 1, 'Negative results cached');
    $failureCalls = 0;
    $failed = new OsmParkResolver(['cache_dir' => $dir], function () use (&$failureCalls): array { $failureCalls++; throw new RuntimeException('timeout'); });
    $throws(fn() => $failed->find('way/5'), ParkSourceUnavailableException::class);
    $throws(fn() => $failed->find('way/5'), ParkSourceUnavailableException::class);
    $assert($failureCalls === 1, 'Failures briefly cached, never converted to 404');
    foreach ([['remark' => 'timeout', 'elements' => []], [], ['elements' => [['type' => 'way', 'id' => 6, 'tags' => ['leisure' => 'park']]]]] as $invalid) {
        $r = new OsmParkResolver(['cache_dir' => ''], fn() => $invalid);
        $throws(fn() => $r->find('way/6'), ParkSourceUnavailableException::class);
    }
    $notPark = new OsmParkResolver(['cache_dir' => ''], fn() => ['elements' => [['type' => 'way', 'id' => 7, 'tags' => ['building' => 'yes']]]]);
    $throws(fn() => $notPark->find('way/7'), ParkNotFoundException::class);
    $file = $dir . '/' . hash('sha256', 'way/2') . '.json';
    $cached = json_decode(file_get_contents($file), true); $cached['expires_at'] = 0;
    file_put_contents($file, json_encode($cached));
    $throws(fn() => $missing->find('way/2'), ParkNotFoundException::class);
    $assert($missingCalls === 2, 'Expired parks refreshed; removed objects no longer served');
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
echo "Park pages: project settings, URL validation, publication/deletion, n/w/r, zero posts, metadata, positive/negative/error caches and expiry passed.\n";
