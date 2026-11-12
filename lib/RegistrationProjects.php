<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

final class RegistrationProjects
{
    /** Only expose enabled, public projects and administrator-managed URLs. */
    public static function present(array $project): ?array
    {
        if (!($project['enabled'] ?? false) || !($project['frontend_public'] ?? false)
            || !($project['recruiting'] ?? false) || ($project['is_deleted'] ?? false)) return null;
        $url = $project['frontend_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) $url = null;
        return [
            'app_key' => (string)$project['app_key'],
            'project_name' => (string)$project['project_name'],
            'frontend_url' => $url,
        ];
    }
}
