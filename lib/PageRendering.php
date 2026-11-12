<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

/** Project page metadata settings, independent from the Activity field schema. */
final class PageRendering
{
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'path_template' => '/parks/{osm_type}/{osm_id}',
            'title_template' => '{name} | {project_name}',
            'default_description' => '',
            'default_image_url' => null,
        ];
    }

    public static function sanitize(mixed $input, ?array $current = null): array
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new ActivityValidationException(['page_rendering' => 'Page rendering must be an object.']);
        }
        $defaults = self::defaults();
        if (array_diff_key($input, $defaults)) {
            throw new ActivityValidationException(['page_rendering' => 'Unknown page rendering setting.']);
        }
        $settings = array_replace($defaults, $current ?? [], $input);
        if (!is_bool($settings['enabled'])) {
            throw new ActivityValidationException(['page_rendering.enabled' => 'Enabled must be true or false.']);
        }
        foreach (['path_template' => 512, 'title_template' => 255, 'default_description' => 1000] as $field => $limit) {
            if ($field === 'default_description' && is_string($settings[$field])) $settings[$field] = preg_replace('/[\r\n\t]+/', ' ', $settings[$field]);
            if (!is_string($settings[$field]) || mb_strlen($settings[$field], 'UTF-8') > $limit
                || preg_match('/[\x00-\x1f\x7f]/', $settings[$field])) {
                throw new ActivityValidationException(['page_rendering.' . $field => 'Invalid text or maximum length exceeded.']);
            }
            $settings[$field] = trim($settings[$field]);
        }
        $path = $settings['path_template'];
        $literalPath = str_replace(['{osm_type}', '{osm_id}'], ['way', '1'], $path);
        if (substr_count($path, '{osm_type}') !== 1 || substr_count($path, '{osm_id}') !== 1
            || !preg_match('#\A/(?:[A-Za-z0-9_~.\-]+/)*[A-Za-z0-9_~.\-]+/?\z#', $literalPath)
            || in_array('.', explode('/', $literalPath), true) || in_array('..', explode('/', $literalPath), true)) {
            throw new ActivityValidationException(['page_rendering.path_template' => 'Use a root-relative path containing {osm_type} and {osm_id} once each.']);
        }
        if ($settings['title_template'] === '' || !str_contains($settings['title_template'], '{name}')
            || preg_match('/[{}]/', str_replace(['{name}', '{project_name}'], '', $settings['title_template']))) {
            throw new ActivityValidationException(['page_rendering.title_template' => 'Use {name} and optional {project_name}.']);
        }
        $image = $settings['default_image_url'];
        if ($image !== null && $image !== '' && (!is_string($image) || !self::isWebUrl($image))) {
            throw new ActivityValidationException(['page_rendering.default_image_url' => 'Image URL must be an absolute HTTP or HTTPS URL without credentials or fragments.']);
        }
        $settings['default_image_url'] = $image === '' ? null : $image;
        return $settings;
    }

    public static function assertFrontend(array $settings, ?string $url, bool $public): void
    {
        if (!$settings['enabled'] || !$public) return;
        if ($url === null || !self::isWebUrl($url) || isset(parse_url($url)['query'])) {
            throw new ActivityValidationException(['frontend_url' => 'Page rendering requires an absolute frontend URL without query or fragment.']);
        }
    }

    public static function isWebUrl(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
        $parts = parse_url($url);
        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host']) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }
}
