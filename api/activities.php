<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';

$method = Http::requireMethods(['GET', 'POST', 'PUT', 'DELETE']);

if ($method === 'GET') {
    ActivityApi::run(function () use ($container): array {
        $appKey = ActivityApi::appKey();
        $activityKey = ActivityApi::optionalActivityKey();
        if ($activityKey !== null) {
            return [200, $container['activity']->find($appKey, $activityKey)];
        }
        $unsupported = ['mode', 'score_min', 'attributes', 'match_mode', 'recent_only', 'photo_only', 'detail_only', 'research_mode', 'page', 'per_page'];
        foreach ($unsupported as $parameter) {
            if (array_key_exists($parameter, $_GET)) {
                throw new ActivityValidationException([$parameter => 'Unsupported Activity parameter.']);
            }
        }
        if (array_key_exists('osmids', $_GET) || array_key_exists('bbox', $_GET)) {
            if (array_key_exists('osmid', $_GET)) {
                throw new ActivityValidationException(['osmid' => 'Cannot combine osmid with osmids or bbox.']);
            }
            $rows = $container['activity']->listSelected($appKey, $_GET);
            if (strtolower((string)($_GET['format'] ?? 'json')) === 'csv') {
                outputCsv($rows, $container['activity_schema']->get($appKey));
            }
            return [200, $rows];
        }
        $osmid = isset($_GET['osmid']) ? trim((string)$_GET['osmid']) : null;
        $rows = $container['activity']->list($appKey, $osmid);
        if (strtolower((string)($_GET['format'] ?? 'json')) === 'csv') {
            outputCsv($rows, $container['activity_schema']->get($appKey));
        }
        return [200, $rows];
    });
}

ActivityApi::run(function () use ($container, $method): array {
    $maxBytes = max(1024, (int)($container['activity_config']['max_payload_bytes'] ?? 262144));
    $input = $method === 'DELETE' ? [] : Http::jsonInput($maxBytes);
    $appKey = ActivityApi::appKey($input);
    $user = $method === 'POST'
        ? ActivityApi::requireWriteAccess($container, $appKey)
        : ActivityApi::requireProjectWriteAccess($container, $appKey);
    $actorUserId = $user === null ? null : (int)$user['id'];
    $input['app'] = $appKey;

    if ($method === 'POST') {
        return [201, $container['activity']->create($input, false, $actorUserId)];
    }

    $activityKey = ActivityApi::activityKey($input);
    if ($method === 'PUT') {
        return [200, $container['activity']->update($activityKey, $input, false, $actorUserId)];
    }

    $container['activity']->delete($appKey, $activityKey);
    return [200, ['status' => 'ok']];
});

function outputCsv(array $rows, array $schema): never
{
    $fields = ['id', 'osmid', 'latitude', 'longitude', 'form_key'];
    foreach (array_keys((array)$schema['fields']) as $field) {
        if (!in_array($field, $fields, true)) $fields[] = $field;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activities.csv"');
    echo "\xEF\xBB\xBF";
    $stream = fopen('php://output', 'wb');
    fputcsv($stream, $fields, ',', '"', '');
    foreach ($rows as $row) {
        $values = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? '';
            $values[] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $value;
        }
        fputcsv($stream, $values, ',', '"', '');
    }
    fclose($stream);
    exit;
}
