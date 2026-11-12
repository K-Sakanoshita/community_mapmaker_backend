<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use Closure;

final class ParkPageService
{
    private Closure $activitySummary;

    public function __construct(private ProjectService $projects, private OsmParkResolver $parks, callable $activitySummary)
    {
        $this->activitySummary = Closure::fromCallable($activitySummary);
    }

    public function find(string $appKey, string $osmid): array
    {
        $project = $this->projects->find($appKey);
        $settings = PageRendering::sanitize($project['page_rendering'] ?? []);
        if (!$project['enabled'] || !$project['frontend_public'] || !$project['frontend_url'] || !$settings['enabled']) {
            throw new ProjectNotFoundException('Public park pages are disabled.');
        }
        PageRendering::assertFrontend($settings, $project['frontend_url'], true);
        $park = $this->parks->find($osmid);
        [$type, $id] = explode('/', $osmid);
        $frontend = parse_url($project['frontend_url']);
        $origin = $frontend['scheme'] . '://' . $frontend['host'] . (isset($frontend['port']) ? ':' . $frontend['port'] : '');
        $canonical = $origin . strtr($settings['path_template'], ['{osm_type}' => $type, '{osm_id}' => $id]);
        $summary = ($this->activitySummary)($appKey, $osmid);
        $description = $park['name'] . 'の公園情報を地図で確認できます。';
        if ($settings['default_description'] !== '') $description .= ' ' . $settings['default_description'];
        return $park + [
            'app_key' => $appKey,
            'title' => strtr($settings['title_template'], ['{name}' => $park['name'], '{project_name}' => $project['project_name']]),
            'description' => $description,
            'canonical_url' => $canonical,
            'image_url' => $settings['default_image_url'],
            'activity_count' => (int)$summary['activity_count'],
            'activity_updated_at' => $summary['updated_at'],
            'source_url' => 'https://www.openstreetmap.org/' . $osmid,
            'attribution' => '© OpenStreetMap contributors',
        ];
    }
}
