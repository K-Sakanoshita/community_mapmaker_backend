<?php
declare(strict_types=1);
namespace CommunityMapMaker\Auth;

use PDO;
use RuntimeException;
use Throwable;

/** Global account lifecycle actions; never exposed by project-level membership endpoints. */
final class AdminUserDeletion
{
    public function __construct(private PDO $pdo, private AuditLogRepositoryInterface $audit, private string $secret = '') {}

    public function delete(int $actorId, int $id, mixed $confirmation): array
    {
        if ($id < 1 || !is_string($confirmation) || strlen($confirmation) > 64) {
            throw new ValidationException(['confirm_userid' => 'Enter the exact user ID to confirm deletion.']);
        }
        $this->pdo->beginTransaction();
        try {
            // Match the existing last-administrator protection's lock order.
            $admins = $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            $query = $this->pdo->prepare('SELECT id, userid, status, role FROM users WHERE id = ? FOR UPDATE');
            $query->execute([$actorId]);
            $actor = $query->fetch();
            if (!$actor || $actor['status'] !== 'active' || $actor['role'] !== 'admin') throw new AdminUserDeletionDenied('admin_required');
            $query->execute([$id]);
            $user = $query->fetch();
            if (!$user) throw new AdminUserNotFoundException('User not found.');
            if (!hash_equals((string)$user['userid'], $confirmation)) throw new ValidationException(['confirm_userid' => 'Enter the exact user ID to confirm deletion.']);
            if ($user['role'] === 'admin' && $user['status'] === 'active' && count($admins) <= 1) throw new LastAdminException('The last active admin cannot be deleted.');
            if ($id === $actorId) throw new AdminUserDeletionDenied('self_deletion_not_allowed');
            if ($user['status'] !== 'deleted') {
                $now = gmdate('Y-m-d H:i:s');
                $update = $this->pdo->prepare("UPDATE users SET status = 'deleted', updated_at = GREATEST(?, DATE_ADD(updated_at, INTERVAL 1 SECOND)) WHERE id = ?");
                $update->execute([$now, $id]);
                $tokens = $this->pdo->prepare('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL');
                $tokens->execute([$now, $id]);
                $this->audit->record($actorId, 'user.soft_delete', 'user', $id, ['previous_status' => $user['status'], 'role' => $user['role']]);
            }
            $this->pdo->commit();
            return ['status' => 'ok', 'user_deleted' => true];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
    public function restore(int $actorId, int $id, mixed $confirmation): array
    {
        return $this->changeDeletedUser($actorId, $id, $confirmation, false);
    }

    public function purge(int $actorId, int $id, mixed $confirmation): array
    {
        return $this->changeDeletedUser($actorId, $id, $confirmation, true);
    }

    private function changeDeletedUser(int $actorId, int $id, mixed $confirmation, bool $purge): array
    {
        if ($id < 1 || !is_string($confirmation) || strlen($confirmation) > 64) {
            throw new ValidationException(['confirm_userid' => 'Enter the exact user ID to confirm deletion.']);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE")->fetchAll();
            $find = $this->pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $find->execute([$actorId]);
            $actor = $find->fetch();
            if (!$actor || $actor['status'] !== 'active' || $actor['role'] !== 'admin') throw new AdminUserDeletionDenied('admin_required');
            $find->execute([$id]);
            $user = $find->fetch();
            if (!$user) throw new AdminUserNotFoundException('User not found.');
            if (!hash_equals((string)$user['userid'], $confirmation)) throw new ValidationException(['confirm_userid' => 'Enter the exact user ID to confirm deletion.']);
            if ($id === $actorId) throw new AdminUserDeletionDenied('self_deletion_not_allowed');
            if ($user['status'] !== 'deleted') throw new AdminUserDeletionDenied('user_not_deleted');

            if ($purge) {
                if ($this->secret === '') throw new RuntimeException('Rate-limit secret is required.');
                // Keep contributed data and projects, removing only account attribution.
                foreach (['activities' => ['created_by_user_id', 'updated_by_user_id'], 'projects' => ['created_by_user_id']] as $table => $columns) {
                    foreach ($columns as $column) {
                        $statement = $this->pdo->prepare("UPDATE $table SET $column = NULL WHERE $column = ?");
                        $statement->execute([$id]);
                    }
                }
                foreach (['auth_tokens', 'user_projects'] as $table) {
                    $statement = $this->pdo->prepare("DELETE FROM $table WHERE user_id = ?");
                    $statement->execute([$id]);
                }
                $statement = $this->pdo->prepare("DELETE FROM admin_audit_logs WHERE (target_type = 'user' AND target_id = ?) OR admin_user_id = ?");
                $statement->execute([$id, $id]);
                $subjects = array_unique(array_filter([
                    'userid:' . $user['userid_normalized'],
                    $user['email_normalized'] === null ? null : 'email:' . $user['email_normalized'],
                    'identity:' . $user['userid_normalized'],
                    $user['email_normalized'] === null ? null : 'identity:' . $user['email_normalized'],
                ]));
                $statement = $this->pdo->prepare('DELETE FROM auth_rate_limits WHERE subject_hash = ?');
                foreach ($subjects as $subject) $statement->execute([hash_hmac('sha256', $subject, $this->secret)]);
                $statement = $this->pdo->prepare('DELETE FROM users WHERE id = ?');
                $statement->execute([$id]);
                // The deletion event retains only the numeric ID, never the removed identity.
                $this->audit->record($actorId, 'user.purge', 'user', $id);
                $result = ['status' => 'ok', 'user_purged' => true];
            } else {
                $history = $this->pdo->prepare("SELECT detail_json FROM admin_audit_logs WHERE target_type = 'user' AND target_id = ? AND action = 'user.soft_delete' ORDER BY id DESC LIMIT 1");
                $history->execute([$id]);
                $detail = json_decode((string)($history->fetchColumn() ?: '{}'), true);
                $status = $detail['previous_status'] ?? null;
                // For records deleted outside this feature, preserve email verification.
                if (!in_array($status, ['pending','active','disabled'], true)) {
                    $status = $user['email'] !== null && $user['email_verified_at'] === null ? 'pending' : 'active';
                }
                if (!in_array($user['role'], ['user','admin'], true)) throw new ValidationException(['role' => 'Role must be user or admin.']);
                $statement = $this->pdo->prepare("UPDATE users SET status = ?, updated_at = GREATEST(UTC_TIMESTAMP(), DATE_ADD(updated_at, INTERVAL 1 SECOND)) WHERE id = ?");
                $statement->execute([$status, $id]);
                // Never revive a link issued before deletion, even for legacy deleted records.
                $statement = $this->pdo->prepare('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL');
                $statement->execute([$id]);
                $this->audit->record($actorId, 'user.restore', 'user', $id, ['restored_status' => $status]);
                $result = ['status' => 'ok', 'user_restored' => true, 'restored_status' => $status];
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

}
final class AdminUserDeletionDenied extends RuntimeException {}
