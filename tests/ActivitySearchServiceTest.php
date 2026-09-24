<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityRepositoryInterface;
use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivitySearchService;
use CommunityMapMaker\Activity\ActivityValidationException;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivitySearchService.php';

final class SearchMemoryRepository implements ActivityRepositoryInterface
{
    public function __construct(private array $rows) {}
    public function list(string $appKey, ?string $osmid = null): array
    {
        return array_values(array_filter($this->rows, static fn(array $row): bool =>
            $row['app_key'] === $appKey && ($osmid === null || $row['osmid'] === $osmid)
        ));
    }
    public function searchRows(string $appKey, ?array $bbox = null, ?array $osmids = null): array
    {
        return array_values(array_filter($this->list($appKey), static function (array $row) use ($bbox, $osmids): bool {
            if ($osmids !== null && !in_array($row['osmid'], $osmids, true)) return false;
            if ($bbox === null) return true;
            if (($row['latitude'] ?? null) === null || ($row['longitude'] ?? null) === null) return false;
            return $row['longitude'] >= $bbox[0] && $row['longitude'] <= $bbox[2]
                && $row['latitude'] >= $bbox[1] && $row['latitude'] <= $bbox[3];
        }));
    }
    public function find(string $appKey, string $activityKey): ?array { return null; }
    public function findForImport(string $appKey, string $activityKey): ?array { return null; }
    public function restoreForImport(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array { return null; }
    public function create(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $createdByUserId = null, ?array $coordinates = null): array { return []; }
    public function update(string $appKey, string $activityKey, ?string $formKey, string $osmid, array $data, ?int $updatedByUserId = null, ?array $coordinates = null): ?array { return null; }
    public function delete(string $appKey, string $activityKey): bool { return false; }
}

function searchRow(string $id, string $osmid, array $data): array
{
    return [
        'app_key' => 'playgrounds', 'activity_key' => $id, 'form_key' => null,
        'osmid' => $osmid, 'data' => $data,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ];
}

function searchAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function searchThrows(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (ActivityValidationException $error) {
        searchAssert(isset($error->errors[$field]), 'Expected validation error for ' . $field . '.');
        return;
    }
    throw new RuntimeException('Expected validation exception for ' . $field . '.');
}

$today = gmdate('Y-m-d');
$rows = [
    searchRow('Playgrounds/1', 'way/100', [
        'actdate' => '2020-01-01', 'score' => 'act_score_4',
        'good_points' => 'act_good_points_1', 'body' => 'memo',
    ]),
    searchRow('Playgrounds/2', 'way/100', [
        'actdate' => $today, 'score' => 'act_score_6',
        'good_points' => ['act_good_points_2'], 'picture_url1' => 'File:Park.jpg',
    ]),
    searchRow('Playgrounds/3', 'node/200', [
        'actdate' => '2020-01-01', 'score' => 'act_score_3',
        'detail_url' => 'https://example.jp/detail',
    ]),
    searchRow('Playgrounds/4', 'way/300', ['actdate' => '2020-01-01']),
];
$apps = [
    'playgrounds' => [
        'schema' => ['fields' => []],
        'search' => ['score_code_offset' => 1, 'recent_days' => 365, 'sparse_information_count' => 2],
    ],
];
$schema = new ActivitySchema($apps);
$service = new ActivitySearchService(new SearchMemoryRepository($rows), $schema, $apps);

$score = $service->search('playgrounds', ['score_min' => 4]);
searchAssert($score['pagination']['total'] === 1, 'Average score filtering must aggregate activities by OSM ID.');
searchAssert($score['items'][0]['osmid'] === 'way/100' && $score['items'][0]['score'] === 4.0, 'Score code offset must match the client calculation.');
searchAssert($score['items'][0]['activity_count'] === 2 && $score['items'][0]['is_recent'], 'Activity count and recent state must be aggregated.');
searchAssert($score['items'][0]['has_photo'] && $score['items'][0]['has_detail'], 'Photo and detail state must be aggregated.');

$attributes = $service->search('playgrounds', [
    'attributes' => 'act_good_points_1,act_good_points_2', 'match_mode' => 'and',
]);
searchAssert($attributes['pagination']['total'] === 1 && $attributes['items'][0]['osmid'] === 'way/100', 'AND attributes must match their union across activities.');

$old = $service->search('playgrounds', ['research_mode' => 'stale']);
searchAssert(array_column($old['items'], 'osmid') === ['node/200'], 'Stale mode must require detail and an old confirmation date.');

$missing = $service->search('playgrounds', [
    'research_mode' => 'missing', 'osmids' => ['way/300', 'relation/400'],
]);
searchAssert($missing['complete'] && $missing['coverage'] === 'requested_osmids', 'Candidate OSM IDs must produce complete coverage metadata.');
searchAssert($missing['candidate_count'] === 2 && $missing['pagination']['total'] === 2, 'Missing mode must include candidates without activities.');
searchAssert(in_array(0, array_column($missing['items'], 'activity_count'), true), 'An OSM ID without activities must have an empty summary.');

$bboxRows = $rows;
$bboxRows[0]['latitude'] = 35.0; $bboxRows[0]['longitude'] = 135.0;
$bboxRows[1]['latitude'] = 36.0; $bboxRows[1]['longitude'] = 136.0;
$bboxRows[2]['latitude'] = 35.5; $bboxRows[2]['longitude'] = 135.5;
$bboxService = new ActivitySearchService(new SearchMemoryRepository($bboxRows), $schema, $apps);
$bounded = $bboxService->search('playgrounds', ['bbox' => '135,35,135.5,35.5']);
searchAssert(array_column($bounded['items'], 'osmid') === ['node/200', 'way/100'], 'BBOX must include boundaries and exclude missing coordinates.');
searchAssert($bounded['items'][1]['activity_count'] === 1 && $bounded['items'][1]['score'] === 3.0, 'BBOX must filter activities before aggregation.');
$boundedCandidates = $bboxService->search('playgrounds', ['bbox' => '135,35,135.5,35.5', 'osmids' => 'way/100,relation/400']);
searchAssert(!$boundedCandidates['complete'] && $boundedCandidates['coverage'] === 'activities_only' && $boundedCandidates['pagination']['total'] === 1, 'BBOX cannot locate candidates without saved coordinates.');
foreach (['135,35,135', '136,35,135,36', '135,91,136,92', 'NaN,35,136,36', '135,35,136,36,37'] as $invalidBbox) {
    searchThrows(fn() => $bboxService->search('playgrounds', ['bbox' => $invalidBbox]), 'bbox');
}

$global = $service->search('playgrounds', ['photo_only' => true, 'per_page' => 1]);
searchAssert(!$global['complete'] && $global['coverage'] === 'activities_only', 'Global search must disclose incomplete park coverage.');
searchAssert($global['pagination']['total'] === 1 && count($global['items']) === 1, 'Photo filtering and pagination must be applied.');

searchThrows(fn() => $service->search('playgrounds', ['score_min' => 6]), 'score_min');
searchThrows(fn() => $service->search('playgrounds', ['match_mode' => 'xor']), 'match_mode');
searchThrows(fn() => $service->search('playgrounds', ['recent_only' => 'sometimes']), 'recent_only');
searchThrows(fn() => $service->search('playgrounds', ['osmids' => 'invalid']), 'osmids');

$or = $service->search('playgrounds', ['attributes' => 'act_good_points_1,unknown', 'match_mode' => 'or']);
searchAssert($or['pagination']['total'] === 1, 'OR must accept one matching attribute.');
$page = $service->search('playgrounds', ['page' => 2, 'per_page' => 1]);
searchAssert($page['pagination']['total'] === 3 && count($page['items']) === 1 && $page['items'][0]['osmid'] !== 'way/100', 'Pagination must retain the full total and skip the first item.');
$priority = $service->search('playgrounds', ['research_mode' => 'missing', 'score_min' => 5, 'photo_only' => true]);
searchAssert(array_column($priority['items'], 'osmid') === ['way/300'], 'Research mode must override normal filters.');
searchAssert($service->search('playgrounds', ['attributes' => '123'])['pagination']['total'] === 0, 'Numeric attribute tokens must not cause a type error.');
foreach (['unknown' => '1,2,3,4', 'page' => 1000001, 'score_min' => [], 'attributes' => [['nested']], 'osmids' => ['key' => 'way/1']] as $field => $value) {
    searchThrows(fn() => $service->search('playgrounds', [$field => $value]), $field);
}
$malformed = new ActivitySearchService(new SearchMemoryRepository([
    searchRow('bad', 'way/1', ['score' => [], 'actdate' => [], 'body' => [], 'picture_url1' => [], 'detail_url' => []]),
]), $schema, $apps);
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
try {
    searchAssert(!$malformed->search('playgrounds', [])['items'][0]['has_detail'], 'Malformed optional values must not count as detail.');
} finally {
    restore_error_handler();
}

echo "Activity search behavior: ok\n";
