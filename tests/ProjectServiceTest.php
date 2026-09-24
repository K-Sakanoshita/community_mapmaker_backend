<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivitySchema;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\DuplicateProjectException;
use CommunityMapMaker\Activity\ProjectRepositoryInterface;
use CommunityMapMaker\Activity\ProjectService;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ProjectRepository.php';
require_once dirname(__DIR__) . '/lib/ProjectService.php';

final class MemoryProjects implements ProjectRepositoryInterface
{
    public array $rows = [];
    public function list(bool $onlyEnabled = false): array { return array_values(array_filter($this->rows, fn(array $row): bool => !$onlyEnabled || $row['enabled'])); }
    public function find(string $appKey): ?array { return $this->rows[$appKey] ?? null; }
    public function create(string $appKey, string $projectName, array $schema, bool $enabled = true): array
    {
        if (isset($this->rows[$appKey])) throw new DuplicateProjectException();
        return $this->rows[$appKey] = ['id' => count($this->rows) + 1, 'app_key' => $appKey, 'project_name' => $projectName, 'schema' => $schema, 'enabled' => $enabled, 'created_at' => 'now', 'updated_at' => 'now'];
    }
    public function update(string $appKey, ?string $projectName, ?array $schema, ?bool $enabled = null): ?array
    {
        if (!isset($this->rows[$appKey])) return null;
        if ($projectName !== null) $this->rows[$appKey]['project_name'] = $projectName;
        if ($schema !== null) $this->rows[$appKey]['schema'] = $schema;
        if ($enabled !== null) $this->rows[$appKey]['enabled'] = $enabled;
        return $this->rows[$appKey];
    }
    public function delete(string $appKey): bool { if (!isset($this->rows[$appKey])) return false; unset($this->rows[$appKey]); return true; }
}

function projectAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function projectThrows(callable $callback, string $class, string $message): void
{
    try { $callback(); } catch (Throwable $error) { projectAssert($error instanceof $class, $message . ': got ' . $error::class); return; }
    throw new RuntimeException($message . ': no exception');
}

$repo = new MemoryProjects();
$schema = new ActivitySchema([], $repo);
$service = new ProjectService($repo, $schema);
$created = $service->create(['app_key' => 'town-map', 'project_name' => 'まち歩き']);
projectAssert($created['app_key'] === 'town-map' && $created['project_name'] === 'まち歩き', 'Project name and immutable app key must be stored separately.');
projectAssert(array_column(array_values($created['schema']['fields']), 'order') === [0, 1, 2], 'Default schema must have deterministic column order.');
projectThrows(fn() => $service->create(['app_key' => 'town-map', 'project_name' => 'duplicate']), DuplicateProjectException::class, 'App key must be unique.');

$updated = $service->update('town-map', ['project_name' => '新しい表示名', 'schema' => ['fields' => [
    'rating' => ['label' => '評価', 'type' => 'select', 'options' => ['3', '2', '1'], 'order' => 20, 'admin' => ['visible' => true, 'editable' => true, 'width' => 120]],
    'memo' => ['label' => 'メモ', 'type' => 'textarea', 'order' => 10, 'admin' => ['visible' => false, 'editable' => true, 'width' => 300]],
]]]);
projectAssert($updated['app_key'] === 'town-map' && $updated['project_name'] === '新しい表示名', 'Updating a project must not change app_key.');
projectAssert(array_keys($updated['schema']['fields']) === ['memo', 'rating'], 'Explicit column order must be normalized and preserved.');
projectAssert($updated['schema']['fields']['rating']['options'] === ['3', '2', '1'], 'Select option order must be preserved.');
projectThrows(
    fn() => $service->sanitizeSchema(['fields' => ['memo' => ['type' => 'text', 'options' => ['invalid']]]]),
    ActivityValidationException::class,
    'Non-choice fields must reject options.'
);
projectThrows(
    fn() => $service->sanitizeSchema(['fields' => ['rating' => ['type' => 'select']]]),
    ActivityValidationException::class,
    'Select fields must require options.'
);
$checkboxSchema = $service->sanitizeSchema(['fields' => [
    'flags' => ['type' => 'checkbox', 'options' => ['one', 'two']],
    'confirmed' => ['type' => 'checkbox'],
]]);
projectAssert($checkboxSchema['fields']['flags']['options'] === ['one', 'two'], 'Checkbox option order must be preserved.');
projectAssert(!isset($checkboxSchema['fields']['confirmed']['options']), 'Boolean-style checkbox fields may omit options.');
projectThrows(fn() => $service->update('town-map', ['schema' => ['fields' => ['id' => ['type' => 'text']]]]), ActivityValidationException::class, 'System columns must not be redefined.');
projectThrows(fn() => $service->update('town-map', ['schema' => ['fields' => ['x' => ['type' => 'unknown']]]]), ActivityValidationException::class, 'Unsupported types must be rejected.');
$service->update('town-map', ['enabled' => false]);
projectAssert(!in_array('town-map', $schema->appKeys(), true), 'Disabled projects must not be exposed as active apps.');
projectThrows(fn() => $schema->get('town-map'), CommunityMapMaker\Activity\UnknownAppException::class, 'Disabled project schemas must not be exposed through the Activity API.');

echo "ProjectService behavior: ok\n";

foreach (['latitude', 'longitude'] as $field) {
    projectThrows(fn() => $service->sanitizeSchema(['fields' => [$field => ['type' => 'number']]]), ActivityValidationException::class, 'Coordinates are reserved metadata.');
}
