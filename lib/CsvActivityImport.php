<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use RuntimeException;

final class CsvActivityImport
{
    private const SYSTEM_HEADERS = [
        'id' => true, 'activity_key' => true, 'osmid' => true, 'form_key' => true,
        'latitude' => true, 'longitude' => true,
        'created_at' => true, 'updated_at' => true,
    ];

    public function __construct(private ActivitySchema $schema)
    {
    }

    public function parse(string $appKey, string $csv): array
    {
        if (trim($csv) === '') {
            throw new ActivityValidationException(['csv' => 'CSV content is empty.']);
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Could not open temporary CSV stream.');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '');
        if ($header === false) {
            fclose($stream);
            throw new ActivityValidationException(['csv' => 'CSV must contain a header row.']);
        }
        $header = array_map(static fn(mixed $value): string => trim((string)$value), $header);
        if (isset($header[0])) $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        $logical = [];
        foreach ($header as $index => $field) {
            if ($field === '') {
                fclose($stream);
                throw new ActivityValidationException(['headers.' . $index => 'CSV header names must not be empty.']);
            }
            $normalized = $field === 'activity_key' ? 'id' : $field;
            if (isset($logical[$normalized])) {
                fclose($stream);
                throw new ActivityValidationException(['headers.' . $index => 'CSV header names must be unique.']);
            }
            if (!isset(self::SYSTEM_HEADERS[$field])) $this->schema->assertKey($field, 'field');
            $logical[$normalized] = true;
        }
        if (!isset($logical['id'])) {
            fclose($stream);
            throw new ActivityValidationException(['headers' => 'CSV must contain an id (or activity_key) column.']);
        }

        $rows = [];
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if (count($values) === 1 && trim((string)$values[0]) === '') continue;
            if (count($values) > count($header)) {
                fclose($stream);
                throw new ActivityValidationException(['rows.' . count($rows) => 'CSV row has more values than the header.']);
            }
            $row = [];
            foreach ($header as $index => $field) {
                $key = $field === 'activity_key' ? 'id' : $field;
                $value = (string)($values[$index] ?? '');
                $row[$key] = in_array($key, ['latitude', 'longitude'], true) && trim($value) === '' ? null : $value;
            }
            $rows[] = $row;
            if (count($rows) > 5000) {
                fclose($stream);
                throw new ActivityValidationException(['rows' => 'At most 5000 activities can be imported at once.']);
            }
        }
        fclose($stream);
        if ($rows === []) throw new ActivityValidationException(['csv' => 'CSV must contain at least one data row.']);

        $schema = $this->schema->get($appKey);
        $existing = (array)($schema['fields'] ?? []);
        $candidate = [];
        $missing = [];
        $changes = [];
        $nextOrder = $this->nextOrder($existing);
        foreach ($header as $field) {
            $field = $field === 'activity_key' ? 'id' : $field;
            if (isset(self::SYSTEM_HEADERS[$field])) continue;
            if (isset($existing[$field])) {
                [$candidate[$field], $fieldChanges] = $this->extendDefinition($field, $existing[$field], $rows);
                if ($fieldChanges !== []) $changes[$field] = $fieldChanges;
                continue;
            }
            $missing[] = $field;
            $candidate[$field] = $this->inferDefinition($field, $rows, $nextOrder++);
        }

        $normalizations = $this->normalizeRows($rows, $candidate);
        $schemaUpdateFields = array_values(array_unique(array_merge($missing, array_keys($changes))));

        return [
            'headers' => array_keys($logical),
            'rows' => $rows,
            'schema_candidate' => ['fields' => $candidate],
            'missing_fields' => $missing,
            'changed_fields' => array_keys($changes),
            'schema_changes' => $changes,
            'schema_update_fields' => $schemaUpdateFields,
            'normalizations' => $normalizations,
        ];
    }

    private function nextOrder(array $fields): int
    {
        $max = -1;
        $position = 0;
        foreach ($fields as $definition) {
            $order = is_array($definition) && isset($definition['order']) && is_numeric($definition['order'])
                ? (int)$definition['order']
                : $position;
            $max = max($max, $order);
            $position++;
        }
        return $max + 1;
    }

    private function extendDefinition(string $field, array $definition, array $rows): array
    {
        $changes = [];
        $type = (string)($definition['type'] ?? 'text');
        if (in_array($type, ['select', 'checkbox'], true) && is_array($definition['options'] ?? null)) {
            $known = [];
            foreach ($definition['options'] as $option) {
                $known[$this->optionValue($option)] = true;
            }
            $additional = [];
            foreach ($rows as $row) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value === '') continue;
                $values = $type === 'checkbox' ? explode(',', $value) : [$value];
                foreach ($values as $item) {
                    $item = trim($item);
                    if ($item !== '' && !isset($known[$item])) $additional[$item] = true;
                }
            }
            $additional = array_keys($additional);
            usort($additional, 'strnatcmp');
            if ($additional !== []) {
                $definition['options'] = array_merge($definition['options'], $additional);
                $changes['added_options'] = $additional;
            }
        }

        if ($type === 'url') {
            $values = $this->values($field, $rows);
            $hasNonUrl = false;
            $allWikimedia = $values !== [];
            foreach ($values as $value) {
                if (filter_var($value, FILTER_VALIDATE_URL) === false) $hasNonUrl = true;
                if (!$this->isWikimediaReference($value)) $allWikimedia = false;
            }
            if ($hasNonUrl && $allWikimedia) {
                $definition['type'] = 'wikimedia';
                $changes['type'] = ['from' => 'url', 'to' => 'wikimedia'];
            }
        }

        return [$definition, $changes];
    }

    private function optionValue(mixed $option): string
    {
        return is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
    }

    private function normalizeRows(array &$rows, array $fields): array
    {
        $normalizations = [];
        foreach ($fields as $field => $definition) {
            if (($definition['type'] ?? 'text') !== 'date') continue;
            foreach ($rows as &$row) {
                $value = (string)($row[$field] ?? '');
                $normalized = $this->normalizeDate($value);
                if ($normalized === $value) continue;
                $row[$field] = $normalized;
                $normalizations[$field] = ($normalizations[$field] ?? 0) + 1;
            }
            unset($row);
        }
        return $normalizations;
    }

    private function normalizeDate(string $value): string
    {
        $trimmed = trim($value);
        if (!preg_match('/\A(\d{4})[\/-](\d{2})[\/-](\d{2})\z/', $trimmed, $parts)) return $value;
        if (!checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) return $value;
        return sprintf('%04d-%02d-%02d', (int)$parts[1], (int)$parts[2], (int)$parts[3]);
    }

    private function isDateValue(string $value): bool
    {
        if (!preg_match('/\A(\d{4})[\/-](\d{2})[\/-](\d{2})\z/', trim($value), $parts)) return false;
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    private function inferDefinition(string $field, array $rows, int $order): array
    {
        $values = $this->values($field, $rows);
        $type = 'text';
        if ($values !== [] && array_reduce($values, static fn(bool $ok, string $value): bool => $ok && is_numeric($value), true)) {
            $type = 'number';
        } elseif ($values !== [] && array_reduce($values, fn(bool $ok, string $value): bool => $ok && $this->isDateValue($value), true)) {
            $type = 'date';
        } elseif ($values !== [] && array_reduce($values, static fn(bool $ok, string $value): bool => $ok && filter_var($value, FILTER_VALIDATE_URL) !== false, true)) {
            $type = 'url';
        } elseif ($values !== [] && array_reduce($values, fn(bool $ok, string $value): bool => $ok && $this->isWikimediaReference($value), true)) {
            $type = 'wikimedia';
        }
        return [
            'label' => $field,
            'type' => $type,
            'required' => false,
            'order' => $order,
            'admin' => ['visible' => true, 'editable' => true, 'width' => 150],
        ];
    }

    private function values(string $field, array $rows): array
    {
        return array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row[$field] ?? '')),
            $rows
        ), static fn(string $value): bool => $value !== ''));
    }

    private function isWikimediaReference(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) return true;
        if (preg_match('/\A(?:File|Image|ファイル):[^\r\n]+\z/iu', $value)) return true;
        return (bool)preg_match('/\A[^\r\n]+\.(?:avif|gif|jpe?g|png|svg|tiff?|webp)\p{Cf}*\z/iu', $value);
    }
}
