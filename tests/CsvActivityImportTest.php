<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\CsvActivityImport;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/CsvActivityImport.php';

function csvAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function csvThrows(callable $callback, string $message): void
{
    try { $callback(); } catch (Throwable $error) { csvAssert($error instanceof ActivityValidationException, $message . ': got ' . $error::class); return; }
    throw new RuntimeException($message . ': no exception');
}

$schema = new ActivitySchema(['town-map' => ['schema' => ['fields' => [
    'title' => ['label' => '題名', 'type' => 'text', 'order' => 0, 'admin' => ['visible' => true, 'editable' => true, 'width' => 160]],
]]]]);
$parser = new CsvActivityImport($schema);
$parsed = $parser->parse('town-map', "\xEF\xBB\xBFactivity_key,osmid,title,score,visit_date,link,memo\r\nA/1,node/1,公園,12,2026-09-03,https://example.com,\"改行\r\nあり\"\r\n");
csvAssert($parsed['rows'][0]['id'] === 'A/1', 'activity_key header must normalize to id.');
csvAssert($parsed['rows'][0]['memo'] === "改行\r\nあり", 'Quoted multiline CSV cells must be preserved.');
csvAssert($parsed['missing_fields'] === ['score', 'visit_date', 'link', 'memo'], 'Undefined headers must be reported for schema review.');
csvAssert($parsed['schema_candidate']['fields']['score']['type'] === 'number', 'Numeric columns must be inferred.');
csvAssert($parsed['schema_candidate']['fields']['visit_date']['type'] === 'date', 'Date columns must be inferred.');
csvAssert($parsed['schema_candidate']['fields']['link']['type'] === 'url', 'URL columns must be inferred.');
csvThrows(fn() => $parser->parse('town-map', "id,activity_key,osmid\nA/1,A/1,node/1\n"), 'Logical duplicate ID headers must be rejected.');
csvThrows(fn() => $parser->parse('town-map', "id,title\nA/1,x\n"), 'Required osmid header must be rejected.');

$legacySchema = new ActivitySchema(['playgrounds' => ['schema' => ['fields' => [
    'actdate' => ['label' => '投稿日', 'type' => 'date', 'order' => 0],
    'score' => ['label' => '評価', 'type' => 'select', 'options' => ['act_score_1'], 'order' => 1],
    'good_points' => ['label' => 'よい点', 'type' => 'checkbox', 'options' => ['act_good_points_1'], 'order' => 2],
    'picture_url1' => ['label' => '写真', 'type' => 'url', 'order' => 3],
]]]]);
$legacyParser = new CsvActivityImport($legacySchema);
$legacy = $legacyParser->parse('playgrounds', "id,osmid,actdate,score,good_points,picture_url1,new_date\nPlaygrounds/1,way/1,2023/04/09,act_score_6,\"act_good_points_1,act_good_points_10\",File:Park.jpg,2026/09/03\n");
csvAssert($legacy['rows'][0]['actdate'] === '2023-04-09' && $legacy['rows'][0]['new_date'] === '2026-09-03', 'Valid slash dates must normalize to ISO format.');
csvAssert($legacy['normalizations'] === ['actdate' => 1, 'new_date' => 1], 'Date normalization counts must be reported by field.');
csvAssert($legacy['schema_candidate']['fields']['new_date']['type'] === 'date', 'Slash-formatted date columns must be inferred as dates.');
csvAssert($legacy['schema_candidate']['fields']['score']['options'] === ['act_score_1', 'act_score_6'], 'Observed select values must extend the schema candidate.');
csvAssert($legacy['schema_candidate']['fields']['good_points']['options'] === ['act_good_points_1', 'act_good_points_10'], 'Observed checkbox values must extend the schema candidate.');
csvAssert($legacy['schema_candidate']['fields']['picture_url1']['type'] === 'wikimedia', 'URL fields containing File references must become Wikimedia candidates.');
csvAssert($legacy['schema_update_fields'] === ['new_date', 'score', 'good_points', 'picture_url1'], 'Missing and changed fields must be listed for schema application.');

echo "CsvActivityImport behavior: ok\n";
