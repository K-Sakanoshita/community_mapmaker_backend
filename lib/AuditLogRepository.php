<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;

final class AuditLogRepository implements AuditLogRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $adminUserId, string $action, string $targetType, ?int $targetId, array $detail = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_audit_logs (admin_user_id, action, target_type, target_id, detail_json, created_at) '
            . 'VALUES (:admin_user_id, :action, :target_type, :target_id, :detail_json, :created_at)'
        );
        $statement->execute([
            ':admin_user_id' => $adminUserId,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':detail_json' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function list(int $page, int $perPage): array
    {
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM admin_audit_logs')->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.admin_user_id, u.userid AS admin_userid, l.action, l.target_type, l.target_id, l.detail_json, l.created_at '
            . 'FROM admin_audit_logs l LEFT JOIN users u ON u.id = l.admin_user_id '
            . 'ORDER BY l.created_at DESC, l.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(static function (array $row): array {
            $detail = $row['detail_json'] === null ? [] : json_decode((string)$row['detail_json'], true, 16, JSON_THROW_ON_ERROR);
            return [
                'id' => (int)$row['id'],
                'admin_user_id' => $row['admin_user_id'] === null ? null : (int)$row['admin_user_id'],
                'admin_userid' => $row['admin_userid'] === null ? null : (string)$row['admin_userid'],
                'action' => (string)$row['action'],
                'target_type' => (string)$row['target_type'],
                'target_id' => $row['target_id'] === null ? null : (int)$row['target_id'],
                'detail' => is_array($detail) ? $detail : [],
                'created_at' => (string)$row['created_at'],
            ];
        }, $statement->fetchAll());
        return ['items' => $items, 'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ]];
    }
}
