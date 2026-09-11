<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use JsonException;
use RuntimeException;

final class ActivitySchema
{
    private array $loaded = [];

    public function __construct(
        private array $apps,
        private ?ProjectRepositoryInterface $projectRepo = null
    ) {
    }

    public function appKeys(): array
    {
        $projects = [];
        if ($this->projectRepo !== null) {
            foreach ($this->projectRepo->list(false) as $project) {
                $projects[$project['app_key']] = $project;
            }
        }
        $keys = [];
        foreach (array_keys($this->apps) as $key) {
            if (is_string($key) && (!isset($projects[$key]) || $projects[$key]['enabled'])) $keys[] = $key;
        }
        foreach ($projects as $project) {
            if ($project['enabled'] && !in_array($project['app_key'], $keys, true)) $keys[] = $project['app_key'];
        }
        return $keys;
    }

    public function appConfig(string $appKey): array
    {
        $this->assertKey($appKey, 'app');
        if ($this->projectRepo !== null) {
            $project = $this->projectRepo->find($appKey);
            if ($project !== null) {
                if (!$project['enabled']) throw new UnknownAppException('Unknown app.');
                $static = is_array($this->apps[$appKey] ?? null) ? $this->apps[$appKey] : [];
                return [
                    'id_prefix' => (string)($static['id_prefix'] ?? $appKey),
                    'project_name' => $project['project_name'],
                    'schema' => $project['schema'],
                    'write_auth_required' => ($static['write_auth_required'] ?? true) === true,
                ];
            }
        }

        $config = $this->apps[$appKey] ?? null;
        if (is_array($config)) return $config;

        throw new UnknownAppException('Unknown app.');
    }

    public function get(string $appKey): array
    {
        if (isset($this->loaded[$appKey])) return $this->loaded[$appKey];

        $config = $this->appConfig($appKey);
        if (isset($config['schema']) && is_array($config['schema'])) {
            $schema = $config['schema'];
        } else {
            $file = (string)($config['schema_file'] ?? '');
            if ($file === '' || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException(sprintf('Activity schema is not readable for app %s.', $appKey));
            }
            try {
                $schema = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException(sprintf('Activity schema is invalid for app %s.', $appKey), 0, $error);
            }
        }
        if (!is_array($schema) || !is_array($schema['fields'] ?? null)) {
            throw new RuntimeException(sprintf('Activity schema for app %s must contain fields.', $appKey));
        }
        foreach ($schema['fields'] as $field => $definition) {
            $this->assertKey((string)$field, 'field');
            if (!is_array($definition)) {
                throw new RuntimeException(sprintf('Activity field %s must be an object.', $field));
            }
        }
        return $this->loaded[$appKey] = $schema;
    }

    public function validate(string $appKey, array $data, bool $migration = false, ?array $fieldDefinitions = null): void
    {
        $fields = $fieldDefinitions ?? $this->get($appKey)['fields'];
        $errors = [];
        foreach ($data as $field => $value) {
            if (!is_string($field)) {
                $errors['data'] = 'Activity data must be a JSON object.';
                continue;
            }
            try {
                $this->assertKey($field, 'field');
            } catch (ActivityValidationException $error) {
                $errors[$field] = $error->errors['field'] ?? 'Invalid field name.';
                continue;
            }
            if (!array_key_exists($field, $fields)) continue;
            $message = $this->validateValue($value, $fields[$field]);
            if ($message !== null) $errors[$field] = $message;
        }
        if (!$migration) {
            foreach ($fields as $field => $definition) {
                if (($definition['required'] ?? false) === true
                    && (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null)) {
                    $errors[$field] = 'This field is required.';
                }
            }
        }
        if ($errors) throw new ActivityValidationException($errors);
    }

    public function assertKey(string $value, string $name): void
    {
        $max = $name === 'activity' ? 128 : 64;
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,' . ($max - 1) . '}\z/', $value)) {
            throw new ActivityValidationException([$name => sprintf('%s must be 1-%d safe characters.', ucfirst($name), $max)]);
        }
    }

    private function validateValue(mixed $value, array $definition): ?string
    {
        if ($value === null || $value === '') return null;
        $type = (string)($definition['type'] ?? 'text');
        $scalar = is_string($value) || is_int($value) || is_float($value);
        if (in_array($type, ['text', 'textarea', 'select', 'url', 'date', 'wikimedia'], true) && !$scalar) {
            return 'Expected a scalar value.';
        }
        if ($type === 'number' && (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))) {
            return 'Expected a numeric value.';
        }
        $text = $scalar ? (string)$value : '';
        if (isset($definition['maxLength']) && strlen($text) > (int)$definition['maxLength']) {
            return sprintf('Must not exceed %d bytes.', (int)$definition['maxLength']);
        }
        if ($type === 'date' && !$this->validDate($text)) return 'Expected a valid YYYY-MM-DD date.';
        if ($type === 'url' && !filter_var($text, FILTER_VALIDATE_URL)) return 'Expected a valid URL.';
        if ($type === 'wikimedia' && !$this->validWikimediaReference($text)) {
            return 'Expected a valid URL or Wikimedia File reference.';
        }
        if ($type === 'select' && isset($definition['options']) && is_array($definition['options'])) {
            $options = array_map(fn(mixed $option): string => is_array($option) ? (string)($option['value'] ?? '') : (string)$option, $definition['options']);
            if (!in_array($text, $options, true)) return 'Value is not an allowed option.';
        }
        if ($type === 'checkbox' && !is_bool($value) && !is_string($value) && !is_array($value)) {
            return 'Expected a boolean, string, or array.';
        }
        if ($type === 'checkbox' && isset($definition['options']) && is_array($definition['options'])) {
            $options = array_map(fn(mixed $option): string => is_array($option) ? (string)($option['value'] ?? '') : (string)$option, $definition['options']);
            $selected = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
            foreach ($selected as $item) {
                if (!is_scalar($item) || !in_array(trim((string)$item), $options, true)) {
                    return 'One or more values are not allowed options.';
                }
            }
        }
        return null;
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts)) return false;
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    private function validWikimediaReference(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) return true;
        if (preg_match('/\A(?:File|Image|ファイル):[^\r\n]+\z/iu', $value)) return true;
        return (bool)preg_match('/\A[^\r\n]+\.(?:avif|gif|jpe?g|png|svg|tiff?|webp)\p{Cf}*\z/iu', $value);
    }
}

final class ActivityValidationException extends RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct('Activity validation failed.');
    }
}

final class UnknownAppException extends RuntimeException
{
}
