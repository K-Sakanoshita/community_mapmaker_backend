<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use RuntimeException;

final class ProjectService
{
    private const RESERVED_FIELDS = [
        'app' => true, 'app_key' => true, 'id' => true, 'activity_key' => true,
        'latitude' => true, 'longitude' => true,
        'form_key' => true, 'osmid' => true, 'created_at' => true, 'updated_at' => true,
    ];

    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ActivitySchema $activitySchema
    ) {
    }

    public function list(bool $onlyEnabled = false): array
    {
        $dbProjects = $this->projects->list($onlyEnabled);
        $dbMap = [];
        foreach ($dbProjects as $p) {
            $dbMap[$p['app_key']] = $p;
        }

        // Include projects defined in static configuration if not in DB
        foreach ($this->activitySchema->appKeys() as $appKey) {
            if (!isset($dbMap[$appKey])) {
                try {
                    $schema = $this->activitySchema->get($appKey);
                    $config = $this->activitySchema->appConfig($appKey);
                    $dbMap[$appKey] = [
                        'id' => null,
                        'app_key' => $appKey,
                        'project_name' => (string)($config['project_name'] ?? $appKey),
                        'schema' => $schema,
                        'enabled' => true,
                        'created_at' => null,
                        'updated_at' => null,
                    ];
                } catch (\Throwable) {
                    // skip if error reading static schema
                }
            }
        }

        return array_values($dbMap);
    }

    public function find(string $appKey): array
    {
        $this->activitySchema->assertKey($appKey, 'app');
        $project = $this->projects->find($appKey);
        if ($project !== null) {
            return $project;
        }

        // fallback to static config
        try {
            $schema = $this->activitySchema->get($appKey);
            $config = $this->activitySchema->appConfig($appKey);
            return [
                'id' => null,
                'app_key' => $appKey,
                'project_name' => (string)($config['project_name'] ?? $appKey),
                'schema' => $schema,
                'enabled' => true,
                'created_at' => null,
                'updated_at' => null,
            ];
        } catch (\Throwable) {
            throw new ProjectNotFoundException('Project not found.');
        }
    }

    public function create(array $input): array
    {
        $projectName = trim((string)($input['project_name'] ?? ''));
        if ($projectName === '') {
            throw new ActivityValidationException(['project_name' => 'Project name is required.']);
        }
        if (mb_strlen($projectName, 'UTF-8') > 255) {
            throw new ActivityValidationException(['project_name' => 'Project name must not exceed 255 characters.']);
        }
        $appKey = trim((string)($input['app_key'] ?? ''));
        if ($appKey === '') {
            throw new ActivityValidationException(['app_key' => 'App key is required.']);
        }
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $appKey)) {
            throw new ActivityValidationException(['app_key' => 'App key must be 1-64 lowercase alphanumeric, underscore or hyphen characters.']);
        }

        $schema = $input['schema'] ?? null;
        if ($schema === null || !is_array($schema)) {
            $schema = [
                'fields' => [
                    'actdate' => ['label' => '投稿日', 'type' => 'date', 'required' => true, 'admin' => ['visible' => true, 'editable' => true, 'width' => 140]],
                    'title' => ['label' => 'タイトル', 'type' => 'text', 'admin' => ['visible' => true, 'editable' => true, 'width' => 200]],
                    'body' => ['label' => '本文', 'type' => 'textarea', 'admin' => ['visible' => true, 'editable' => true, 'width' => 300]],
                ]
            ];
        }
        $schema = $this->sanitizeSchema($schema);

        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : true;
        return $this->projects->create($appKey, $projectName, $schema, $enabled);
    }

    public function update(string $appKey, array $input): array
    {
        $this->activitySchema->assertKey($appKey, 'app');
        $projectName = isset($input['project_name']) ? trim((string)$input['project_name']) : null;
        if ($projectName === '') {
            throw new ActivityValidationException(['project_name' => 'Project name cannot be empty.']);
        }
        if ($projectName !== null && mb_strlen($projectName, 'UTF-8') > 255) {
            throw new ActivityValidationException(['project_name' => 'Project name must not exceed 255 characters.']);
        }

        $schema = null;
        if (isset($input['schema'])) {
            if (!is_array($input['schema'])) {
                throw new ActivityValidationException(['schema' => 'Schema must be an object.']);
            }
            $schema = $this->sanitizeSchema($input['schema']);
        }

        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : null;

        $existing = $this->projects->find($appKey);
        if ($existing === null) {
            // If it exists in static config, initialize it in DB
            $current = $this->find($appKey);
            return $this->projects->create(
                $appKey,
                $projectName ?? $current['project_name'],
                $schema ?? $current['schema'],
                $enabled ?? $current['enabled']
            );
        }

        $updated = $this->projects->update($appKey, $projectName, $schema, $enabled);
        if ($updated === null) {
            throw new ProjectNotFoundException('Project not found.');
        }
        return $updated;
    }

    public function delete(string $appKey): void
    {
        $this->activitySchema->assertKey($appKey, 'app');
        if (!$this->projects->delete($appKey)) {
            throw new ProjectNotFoundException('Project not found.');
        }
    }

    public function sanitizeSchema(array $schema): array
    {
        $fields = $schema['fields'] ?? null;
        if (!is_array($fields)) {
            throw new ActivityValidationException(['schema' => 'Schema must contain a "fields" object.']);
        }
        if (array_is_list($fields) && $fields !== []) {
            throw new ActivityValidationException(['schema' => 'Schema fields must be a JSON object keyed by field name.']);
        }
        if (count($fields) > 256) {
            throw new ActivityValidationException(['schema' => 'Schema may contain at most 256 fields.']);
        }

        $preparedFields = [];
        $position = 0;
        foreach ($fields as $field => $def) {
            $fieldStr = trim((string)$field);
            $this->activitySchema->assertKey($fieldStr, 'field');
            if (isset(self::RESERVED_FIELDS[$fieldStr])) {
                throw new ActivityValidationException([$fieldStr => 'System columns cannot be added to schema fields.']);
            }
            if (!is_array($def)) {
                throw new ActivityValidationException([$fieldStr => 'Field definition must be an object.']);
            }

            $type = (string)($def['type'] ?? 'text');
            $allowedTypes = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url', 'datetime', 'image', 'wikimedia', 'boolean'];
            if (!in_array($type, $allowedTypes, true)) {
                throw new ActivityValidationException([$fieldStr . '.type' => 'Unsupported field type.']);
            }

            $label = trim((string)($def['label'] ?? $fieldStr));
            if ($label === '') $label = $fieldStr;
            if (mb_strlen($label, 'UTF-8') > 255) {
                throw new ActivityValidationException([$fieldStr . '.label' => 'Label must not exceed 255 characters.']);
            }

            $cleanDef = [
                'label' => $label,
                'type' => $type,
                'required' => (bool)($def['required'] ?? false),
            ];

            if (isset($def['maxLength']) && is_numeric($def['maxLength'])) {
                $maxLength = (int)$def['maxLength'];
                if ($maxLength < 1 || $maxLength > 1000000) {
                    throw new ActivityValidationException([$fieldStr . '.maxLength' => 'Maximum length must be between 1 and 1000000.']);
                }
                $cleanDef['maxLength'] = $maxLength;
            }

            $hasOptions = array_key_exists('options', $def);
            $supportsOptions = in_array($type, ['select', 'checkbox'], true);
            if ($hasOptions && !$supportsOptions) {
                throw new ActivityValidationException([
                    $fieldStr . '.options' => 'Options are only supported for select and checkbox fields.',
                ]);
            }
            if ($hasOptions) {
                if (!is_array($def['options'])) {
                    throw new ActivityValidationException([$fieldStr . '.options' => 'Options must be an array.']);
                }
                $cleanDef['options'] = $this->sanitizeOptions($fieldStr, $def['options']);
            } elseif ($type === 'select') {
                throw new ActivityValidationException([$fieldStr . '.options' => 'Select fields require at least one option.']);
            }

            $admin = isset($def['admin']) && is_array($def['admin']) ? $def['admin'] : [];
            $width = isset($admin['width']) && is_numeric($admin['width']) ? (int)$admin['width'] : 150;
            if ($width < 80 || $width > 800) {
                throw new ActivityValidationException([$fieldStr . '.admin.width' => 'Column width must be between 80 and 800 pixels.']);
            }
            $cleanDef['admin'] = [
                'visible' => isset($admin['visible']) ? (bool)$admin['visible'] : true,
                'editable' => isset($admin['editable']) ? (bool)$admin['editable'] : true,
                'width' => $width,
            ];

            $order = isset($def['order']) && is_numeric($def['order']) ? (int)$def['order'] : $position;
            $preparedFields[] = compact('fieldStr', 'cleanDef', 'order', 'position');
            $position++;
        }

        usort($preparedFields, fn(array $a, array $b): int => [$a['order'], $a['position']] <=> [$b['order'], $b['position']]);
        $sanitizedFields = [];
        foreach ($preparedFields as $index => $item) {
            $definition = $item['cleanDef'];
            $definition['order'] = $index;
            $sanitizedFields[$item['fieldStr']] = $definition;
        }
        return ['fields' => $sanitizedFields];
    }

    private function sanitizeOptions(string $field, array $options): array
    {
        $clean = [];
        $seen = [];
        foreach ($options as $index => $option) {
            if (is_array($option)) {
                if (!array_key_exists('value', $option) || !is_scalar($option['value'])) {
                    throw new ActivityValidationException([$field . '.options.' . $index => 'Option value must be a scalar.']);
                }
                $value = trim((string)$option['value']);
                $label = trim((string)($option['label'] ?? $value));
                $item = ['value' => $value, 'label' => $label === '' ? $value : $label];
            } elseif (is_scalar($option)) {
                $value = trim((string)$option);
                $item = $value;
            } else {
                throw new ActivityValidationException([$field . '.options.' . $index => 'Option must be a scalar or value/label object.']);
            }
            if ($value === '' || mb_strlen($value, 'UTF-8') > 255) {
                throw new ActivityValidationException([$field . '.options.' . $index => 'Option value must be 1-255 characters.']);
            }
            if (isset($seen[$value])) {
                throw new ActivityValidationException([$field . '.options.' . $index => 'Option values must be unique.']);
            }
            $seen[$value] = true;
            $clean[] = $item;
        }
        if ($clean === []) {
            throw new ActivityValidationException([$field . '.options' => 'Options must not be empty.']);
        }
        return $clean;
    }
}

final class ProjectNotFoundException extends RuntimeException
{
}
