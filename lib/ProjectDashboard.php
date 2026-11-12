<?php
declare(strict_types=1);
namespace CommunityMapMaker\Activity;

use CommunityMapMaker\Auth\ProjectAccessDeniedException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ProjectDashboard
{
    public function __construct(private PDO $pdo) {}

    public function read(array $actor, string $appKey, int $page = 1, ?DateTimeImmutable $now = null): array
    {
        // Recheck membership on every request, including private and stopped projects.
        $query = $this->pdo->prepare('SELECT p.app_key, p.project_name, p.enabled, up.role FROM projects p LEFT JOIN user_projects up ON up.project_id = p.id AND up.user_id = ? WHERE p.app_key = ? AND p.is_deleted = 0');
        $query->execute([(int)$actor['id'], $appKey]);
        $project = $query->fetch();
        if (!$project) throw new ProjectNotFoundException('Project not found.');
        if (($actor['role'] ?? '') !== 'admin' && !in_array($project['role'], ['viewer', 'contributor', 'editor', 'project_admin'], true)) {
            throw new ProjectAccessDeniedException('Project membership is required.');
        }
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Tokyo'));
        $utc = static fn(DateTimeImmutable $date): string => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today = $now->setTime(0, 0);
        $query = $this->pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(created_at >= :today AND created_at <= :today_end), 0) AS today, COALESCE(SUM(created_at >= :week AND created_at <= :week_end), 0) AS week, COALESCE(SUM(created_at >= :month AND created_at <= :month_end), 0) AS month FROM activities WHERE app_key = :app AND is_deleted = 0');
        $query->execute([':app' => $appKey, ':today' => $utc($today), ':week' => $utc($today->modify('-6 days')), ':month' => $utc($today->modify('-29 days')), ':today_end' => $utc($now), ':week_end' => $utc($now), ':month_end' => $utc($now)]);
        $counts = array_map('intval', $query->fetch());
        $perPage = 20;
        $pages = max(1, (int)ceil($counts['total'] / $perPage));
        $page = min($pages, max(1, $page));
        $query = $this->pdo->prepare('SELECT activity_key, form_key, osmid, data_json, created_at FROM activities WHERE app_key = :app AND is_deleted = 0 ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset');
        $query->bindValue(':app', $appKey);
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $query->execute();
        $items = array_map(static fn(array $row): array => [
            'id' => $row['activity_key'], 'form' => $row['form_key'], 'osmid' => $row['osmid'],
            'data' => json_decode($row['data_json'], true, 512, JSON_THROW_ON_ERROR),
            'created_at' => str_replace(' ', 'T', $row['created_at']) . 'Z',
        ], $query->fetchAll());
        return ['project' => ['app_key' => $project['app_key'], 'project_name' => $project['project_name'], 'enabled' => (bool)$project['enabled']],
            'counts' => $counts, 'as_of' => $now->format(DATE_ATOM), 'timezone' => 'Asia/Tokyo', 'items' => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $counts['total'], 'total_pages' => $pages]];
    }
}
