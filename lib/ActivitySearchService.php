<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ActivitySearchService
{
    public function __construct(
        private ActivityRepositoryInterface $repository,
        private ActivitySchema $schema,
        private array $apps
    ) {
    }

    public function search(string $appKey, array $input): array
    {
        $this->schema->appConfig($appKey);
        $criteria = $this->criteria($input);
        $config = $this->config($appKey);
        $rows = $this->repository->list($appKey);
        if ($criteria['bbox'] !== null) {
            $rows = array_values(array_filter($rows, fn(array $row): bool => $this->inBbox($row, $criteria['bbox'])));
        }
        $records = $this->summaries($rows, $config);

        if ($criteria['osmids'] !== null) {
            $byOsmid = [];
            foreach ($records as $record) $byOsmid[$record['osmid']] = $record;
            $records = array_map(
                fn(string $osmid): array => $byOsmid[$osmid] ?? $this->emptySummary($osmid),
                $criteria['osmids']
            );
            if ($criteria['bbox'] !== null) {
                $records = array_values(array_filter($records, static fn(array $record): bool => $record['activity_count'] > 0));
            }
        }

        $matches = array_values(array_filter(
            $records,
            fn(array $record): bool => $this->matches($record, $criteria, $config)
        ));
        usort($matches, static function (array $left, array $right): int {
            return strcmp((string)$right['confirmed'], (string)$left['confirmed'])
                ?: strcmp((string)$left['osmid'], (string)$right['osmid']);
        });

        $total = count($matches);
        $page = $criteria['page'];
        $perPage = $criteria['per_page'];
        $coverage = $criteria['osmids'] === null || $criteria['bbox'] !== null ? 'activities_only' : 'requested_osmids';

        return [
            'items' => array_slice($matches, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int)ceil($total / $perPage),
            ],
            'criteria' => array_diff_key($criteria, ['osmids' => true, 'page' => true, 'per_page' => true]),
            'coverage' => $coverage,
            'complete' => $coverage === 'requested_osmids',
            'candidate_count' => $criteria['osmids'] === null ? null : count($criteria['osmids']),
        ];
    }

    private function criteria(array $input): array
    {
        $allowed = ['app', 'score_min', 'attributes', 'match_mode', 'recent_only', 'photo_only', 'detail_only', 'research_mode', 'osmids', 'bbox', 'page', 'per_page'];
        foreach ($input as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new ActivityValidationException([(string)$key => 'Unsupported search parameter.']);
            }
            if (in_array($key, ['attributes', 'osmids'], true)) {
                if (!is_string($value) && !is_array($value)) {
                    throw new ActivityValidationException([$key => 'Expected a string or list of strings.']);
                }
                if (is_array($value) && (!array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value))) {
                    throw new ActivityValidationException([$key => 'Expected a list of strings.']);
                }
            } elseif (!is_scalar($value)) {
                throw new ActivityValidationException([$key => 'Expected a scalar value.']);
            }
        }
        $scoreMin = $this->number($input['score_min'] ?? 0, 'score_min');
        if ($scoreMin < 0 || $scoreMin > 5) {
            throw new ActivityValidationException(['score_min' => 'Score minimum must be between 0 and 5.']);
        }

        $matchMode = strtolower(trim((string)($input['match_mode'] ?? 'and')));
        if (!in_array($matchMode, ['and', 'or'], true)) {
            throw new ActivityValidationException(['match_mode' => 'Match mode must be and or or.']);
        }

        $researchMode = strtolower(trim((string)($input['research_mode'] ?? '')));
        if (!in_array($researchMode, ['', 'missing', 'stale', 'photo', 'sparse'], true)) {
            throw new ActivityValidationException(['research_mode' => 'Unknown research mode.']);
        }

        $page = $this->integer($input['page'] ?? 1, 'page', 1, 1000000);
        $perPage = $this->integer($input['per_page'] ?? 100, 'per_page', 1, 500);

        return [
            'score_min' => $scoreMin,
            'attributes' => $this->values($input['attributes'] ?? []),
            'match_mode' => $matchMode,
            'recent_only' => $this->boolean($input['recent_only'] ?? false, 'recent_only'),
            'photo_only' => $this->boolean($input['photo_only'] ?? false, 'photo_only'),
            'detail_only' => $this->boolean($input['detail_only'] ?? false, 'detail_only'),
            'research_mode' => $researchMode,
            'osmids' => array_key_exists('osmids', $input) ? $this->osmids($input['osmids']) : null,
            'bbox' => array_key_exists('bbox', $input) ? $this->bbox($input['bbox']) : null,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function bbox(mixed $value): array
    {
        if (!is_string($value)) throw new ActivityValidationException(['bbox' => 'Expected west,south,east,north.']);
        $parts = explode(',', $value);
        if (count($parts) !== 4) throw new ActivityValidationException(['bbox' => 'Expected west,south,east,north.']);
        $bounds = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !is_numeric($part) || !is_finite((float)$part)) {
                throw new ActivityValidationException(['bbox' => 'Expected finite numeric bounds.']);
            }
            $bounds[] = (float)$part;
        }
        [$west, $south, $east, $north] = $bounds;
        if ($west < -180 || $west > 180 || $east < -180 || $east > 180 || $south < -90 || $south > 90 || $north < -90 || $north > 90 || $west >= $east || $south >= $north) {
            throw new ActivityValidationException(['bbox' => 'Bounds must be within longitude/latitude ranges and ordered west < east, south < north.']);
        }
        return $bounds;
    }

    private function inBbox(array $row, array $bbox): bool
    {
        $latitude = $row['latitude'] ?? null;
        $longitude = $row['longitude'] ?? null;
        if ($latitude === null || $longitude === null || !is_numeric($latitude) || !is_numeric($longitude)) return false;
        return (float)$longitude >= $bbox[0] && (float)$longitude <= $bbox[2]
            && (float)$latitude >= $bbox[1] && (float)$latitude <= $bbox[3];
    }

    private function summaries(array $rows, array $config): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $osmid = trim((string)($row['osmid'] ?? ''));
            if ($osmid === '') continue;
            $groups[$osmid][] = $row;
        }
        return array_map(
            fn(string $osmid, array $activities): array => $this->summary($osmid, $activities, $config),
            array_keys($groups),
            array_values($groups)
        );
    }

    private function summary(string $osmid, array $activities, array $config): array
    {
        $scoreValues = [];
        $attributes = [];
        $hasPhoto = false;
        $memo = '';
        $hasDetailUrl = false;
        $latest = null;

        foreach ($activities as $activity) {
            $data = (array)($activity['data'] ?? []);
            $score = $this->score($data[$config['score_field']] ?? null, $config);
            if ($score > 0) $scoreValues[] = $score;
            foreach ($this->splitValues($data[$config['attributes_field']] ?? []) as $attribute) {
                $attributes[$attribute] = true;
            }
            $hasPhoto = $hasPhoto || $this->hasPhoto($data, $config['photo_field_pattern']);
            if ($memo === '') $memo = $this->text($data[$config['body_field']] ?? null);
            $hasDetailUrl = $hasDetailUrl || $this->text($data[$config['detail_url_field']] ?? null) !== '';
            if ($latest === null || $this->compareActivityDate($activity, $latest, $config['date_fields']) > 0) {
                $latest = $activity;
            }
        }

        $average = $scoreValues === [] ? 0.0 : round(array_sum($scoreValues) / count($scoreValues), 1);
        $attributeValues = array_map('strval', array_keys($attributes));
        $confirmedTime = $latest === null ? 0 : $this->activityTime($latest, $config['date_fields']);
        $hasDetail = $average > 0 || $attributeValues !== [] || $hasPhoto || $memo !== '' || $hasDetailUrl;
        $informationCount = (int)($average > 0) + count($attributeValues) + (int)$hasPhoto
            + (int)($memo !== '') + (int)$hasDetailUrl;

        return [
            'osmid' => $osmid,
            'activity_count' => count($activities),
            'score' => $average,
            'attributes' => $attributeValues,
            'confirmed' => $confirmedTime > 0 ? gmdate('Y-m-d\TH:i:s\Z', $confirmedTime) : '',
            'has_photo' => $hasPhoto,
            'has_detail' => $hasDetail,
            'memo' => $memo,
            'latest_activity_id' => (string)($latest['activity_key'] ?? ''),
            'is_recent' => $confirmedTime >= time() - $config['recent_days'] * 86400,
            'information_count' => $informationCount,
        ];
    }

    private function emptySummary(string $osmid): array
    {
        return [
            'osmid' => $osmid,
            'activity_count' => 0,
            'score' => 0.0,
            'attributes' => [],
            'confirmed' => '',
            'has_photo' => false,
            'has_detail' => false,
            'memo' => '',
            'latest_activity_id' => '',
            'is_recent' => false,
            'information_count' => 0,
        ];
    }

    private function matches(array $record, array $criteria, array $config): bool
    {
        if ($criteria['research_mode'] !== '') {
            return match ($criteria['research_mode']) {
                'missing' => !$record['has_detail'],
                'stale' => $record['has_detail'] && !$record['is_recent'],
                'photo' => !$record['has_photo'],
                'sparse' => $record['information_count'] < $config['sparse_information_count'],
            };
        }
        if ($record['score'] < $criteria['score_min']) return false;
        if ($criteria['recent_only'] && !$record['is_recent']) return false;
        if ($criteria['photo_only'] && !$record['has_photo']) return false;
        if ($criteria['detail_only'] && !$record['has_detail']) return false;
        if ($criteria['attributes'] !== []) {
            $count = count(array_intersect($criteria['attributes'], $record['attributes']));
            if ($criteria['match_mode'] === 'or' ? $count === 0 : $count !== count($criteria['attributes'])) return false;
        }
        return true;
    }

    private function config(string $appKey): array
    {
        $search = (array)($this->apps[$appKey]['search'] ?? []);
        return [
            'score_field' => (string)($search['score_field'] ?? 'score'),
            'attributes_field' => (string)($search['attributes_field'] ?? 'good_points'),
            'body_field' => (string)($search['body_field'] ?? 'body'),
            'detail_url_field' => (string)($search['detail_url_field'] ?? 'detail_url'),
            'date_fields' => $this->configuredFields($search['date_fields'] ?? ['actdate', 'updatetime']),
            'photo_field_pattern' => (string)($search['photo_field_pattern'] ?? '/^picture_url\d+$/'),
            'score_code_pattern' => (string)($search['score_code_pattern'] ?? '/^act_score_(\d+)$/'),
            'score_code_offset' => (float)($search['score_code_offset'] ?? 0),
            'recent_days' => max(1, (int)($search['recent_days'] ?? 365)),
            'sparse_information_count' => max(1, (int)($search['sparse_information_count'] ?? 2)),
        ];
    }

    private function configuredFields(mixed $value): array
    {
        if (!is_array($value)) return ['actdate', 'updatetime'];
        $fields = array_values(array_filter(array_map('strval', $value), static fn(string $field): bool => $field !== ''));
        return $fields === [] ? ['actdate', 'updatetime'] : $fields;
    }

    private function score(mixed $value, array $config): float
    {
        $text = $this->text($value);
        $matches = [];
        if ($text !== '' && @preg_match($config['score_code_pattern'], $text, $matches) === 1 && isset($matches[1])) {
            return max(0.0, min(5.0, (float)$matches[1] - $config['score_code_offset']));
        }
        return is_numeric($text) ? max(0.0, min(5.0, (float)$text)) : 0.0;
    }

    private function hasPhoto(array $data, string $pattern): bool
    {
        foreach ($data as $field => $value) {
            if (@preg_match($pattern, (string)$field) === 1 && $this->text($value) !== '') return true;
        }
        return false;
    }

    private function compareActivityDate(array $left, array $right, array $fields): int
    {
        foreach ($fields as $field) {
            $comparison = $this->timestamp($left['data'][$field] ?? null) <=> $this->timestamp($right['data'][$field] ?? null);
            if ($comparison !== 0) return $comparison;
        }
        return strcmp((string)($left['activity_key'] ?? ''), (string)($right['activity_key'] ?? ''));
    }

    private function activityTime(array $activity, array $fields): int
    {
        foreach ($fields as $field) {
            $time = $this->timestamp($activity['data'][$field] ?? null);
            if ($time > 0) return $time;
        }
        return 0;
    }

    private function timestamp(mixed $value): int
    {
        $text = $this->text($value);
        if ($text === '') return 0;
        try {
            return (new DateTimeImmutable($text, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    private function values(mixed $value): array
    {
        $values = $this->splitValues($value);
        if (count($values) > 50) throw new ActivityValidationException(['attributes' => 'At most 50 attributes may be searched.']);
        foreach ($values as $item) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/', $item)) {
                throw new ActivityValidationException(['attributes' => 'One or more attributes are invalid.']);
            }
        }
        return $values;
    }

    private function splitValues(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string)$value);
        $result = [];
        foreach ($values as $item) {
            if (!is_scalar($item)) continue;
            foreach (explode(',', (string)$item) as $part) {
                $part = trim($part);
                if ($part !== '') $result[$part] = true;
            }
        }
        return array_map('strval', array_keys($result));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function osmids(mixed $value): array
    {
        $values = $this->splitValues($value);
        if (count($values) > 1000) throw new ActivityValidationException(['osmids' => 'At most 1000 OSM IDs may be searched.']);
        foreach ($values as $osmid) {
            if (!preg_match('/\A(?:node|way|relation)\/[1-9][0-9]{0,18}\z/', $osmid)) {
                throw new ActivityValidationException(['osmids' => 'One or more OSM IDs are invalid.']);
            }
        }
        return $values;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) return $value;
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['', '0', 'false', 'no', 'off'], true)) return false;
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) return true;
        throw new ActivityValidationException([$field => 'Expected a boolean value.']);
    }

    private function number(mixed $value, string $field): float
    {
        if (!is_numeric($value) || !is_finite((float)$value)) throw new ActivityValidationException([$field => 'Expected a finite numeric value.']);
        return (float)$value;
    }

    private function integer(mixed $value, string $field, int $minimum, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ActivityValidationException([$field => 'Expected an integer value.']);
        }
        $number = (int)$value;
        if ($number < $minimum || $number > $maximum) {
            throw new ActivityValidationException([$field => sprintf('Must be between %d and %d.', $minimum, $maximum)]);
        }
        return $number;
    }
}
