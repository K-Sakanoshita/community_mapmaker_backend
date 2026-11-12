<?php
declare(strict_types=1);
namespace CommunityMapMaker\Activity;

use CommunityMapMaker\Auth\AuditLogRepositoryInterface;
use CommunityMapMaker\Auth\ProjectAccessDeniedException;
use PDO;
use RuntimeException;
use Throwable;

/** Project-scoped, reversible bulk deletion. No row content is transferred to the browser. */
final class ActivityBulkDeletion
{
    public function __construct(private PDO $pdo, private AuditLogRepositoryInterface $audit) {}

    public function execute(int $actorId, string $appKey, string $method, array $input = [], ?array $staticProject = null): array
    {
        if (!in_array($method, ['GET','POST'], true)) throw new ActivityValidationException(['method'=>'Unsupported action.']);
        if ($method === 'POST' && (!is_string($input['confirm_app_key'] ?? null) || $input['confirm_app_key'] !== $appKey)) {
            throw new ActivityValidationException(['confirm_app_key'=>'Enter the exact project key to move all activities to deleted.']);
        }
        $maxId = $input['snapshot_max_id'] ?? null;
        $expected = filter_var($input['expected_count'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($method === 'POST' && (!is_string($maxId) || !preg_match('/\A[1-9][0-9]{0,19}\z/', $maxId) || $expected === false || $expected === null)) {
            throw new ActivityValidationException(['snapshot'=>'A valid deletion preview is required.']);
        }
        $this->pdo->beginTransaction();
        try {
            $find=$this->pdo->prepare('SELECT id, app_key, project_name, is_deleted FROM projects WHERE app_key = ? FOR UPDATE');
            $find->execute([$appKey]); $project=$find->fetch();
            if (!$project && $staticProject !== null && array_key_exists('id', $staticProject) && $staticProject['id'] === null && ($staticProject['app_key'] ?? null) === $appKey) $project=$staticProject;
            if (!$project || ($project['is_deleted'] ?? false)) throw new ProjectNotFoundException('Project not found.');
            $find=$this->pdo->prepare('SELECT id, role, status FROM users WHERE id = ? FOR UPDATE');
            $find->execute([$actorId]); $actor=$find->fetch();
            $role=false;
            if ($project['id'] !== null) {
                $membership=$this->pdo->prepare('SELECT role FROM user_projects WHERE project_id = ? AND user_id = ? FOR UPDATE');
                $membership->execute([$project['id'],$actorId]); $role=$membership->fetchColumn();
            }
            if (!$actor || $actor['status']!=='active' || !in_array($actor['role'],['user','admin'],true) || ($actor['role']!=='admin' && $role!=='project_admin')) {
                throw new ProjectAccessDeniedException('Project administration is required.');
            }
            if ($method === 'GET') {
                $count=$this->pdo->prepare('SELECT COUNT(*) AS total, COALESCE(MAX(id),0) AS max_id FROM activities WHERE app_key = ? AND is_deleted = 0');
                $count->execute([$appKey]); $row=$count->fetch();
                $result=['app_key'=>$appKey,'project_name'=>$project['project_name'],'count'=>(int)$row['total'],'snapshot_max_id'=>(string)$row['max_id']];
            } else {
                // Lock only IDs, excluding posts created after the preview's high-water mark.
                $lock=$this->pdo->prepare('SELECT id FROM activities WHERE app_key = ? AND is_deleted = 0 AND id <= ? ORDER BY id FOR UPDATE');
                $lock->execute([$appKey,$maxId]); $count=0;
                while ($lock->fetchColumn() !== false) $count++;
                if ($count !== $expected) throw new ActivityBulkDeletionConflict('activity_delete_conflict');
                $update=$this->pdo->prepare('UPDATE activities SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), updated_by_user_id = ? WHERE app_key = ? AND is_deleted = 0 AND id <= ?');
                $update->execute([$actorId,$appKey,$maxId]);
                if ($update->rowCount() !== $count) throw new ActivityBulkDeletionConflict('activity_delete_conflict');
                $this->audit->record($actorId,'activities.soft_delete_all','project',$project['id']===null?null:(int)$project['id'],['app_key'=>$appKey,'count'=>$count,'snapshot_max_id'=>$maxId]);
                $remaining=$this->pdo->prepare('SELECT COUNT(*) FROM activities WHERE app_key = ? AND is_deleted = 0');
                $remaining->execute([$appKey]);
                $result=['status'=>'ok','deleted_count'=>$count,'remaining_count'=>(int)$remaining->fetchColumn()];
            }
            $this->pdo->commit(); return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
final class ActivityBulkDeletionConflict extends RuntimeException {}
