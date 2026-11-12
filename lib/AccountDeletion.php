<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use RuntimeException;
use Throwable;

final class AccountDeletion
{
    public function __construct(private PDO $pdo, private RateLimiterInterface $limits, private string $secret) {}

    public function delete(array $input, string $client): void
    {
        $identity = $input['identity'] ?? null;
        $password = $input['password'] ?? null;
        $confirmation = $input['confirm_userid'] ?? null;
        if (!is_string($identity) || !is_string($password) || !is_string($confirmation)
            || strlen($identity) > 255 || strlen($password) > 4096 || strlen($confirmation) > 64
            || trim($identity) === '' || $password === '') {
            throw new ValidationException(['identity' => 'Identity, password and user ID confirmation are required.']);
        }
        $normalized = mb_strtolower(trim($identity), 'UTF-8');
        if (!$this->limits->hit('delete_account_client', 'client:' . $client, 10, 3600)
            || !$this->limits->hit('delete_account_identity', 'identity:' . $normalized, 5, 3600)) {
            throw new RateLimitExceededException('Too many account deletion attempts.');
        }
        $find = $this->pdo->prepare('SELECT * FROM users WHERE userid_normalized = :userid OR email_normalized = :email LIMIT 1');
        $find->execute([':userid' => $normalized, ':email' => $normalized]);
        $authenticated = $find->fetch();
        // Verify before taking administrative locks, including a hash for unknown identities.
        $hash = $authenticated === false ? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.' : (string)$authenticated['password_hash'];
        $valid = password_verify($password, $hash);
        if (!$valid || $authenticated === false || $authenticated['status'] === 'deleted') throw new AccountCredentialsException();
        $this->pdo->beginTransaction();
        try {
            // Use the same lock order as administrator role/status updates.
            $admins = $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            $find = $this->pdo->prepare('SELECT * FROM users WHERE id = :id FOR UPDATE');
            $find->execute([':id' => $authenticated['id']]);
            $user = $find->fetch();
            // A concurrent password change or deletion invalidates the initial verification.
            if ($user === false || $user['status'] === 'deleted' || !hash_equals($hash, (string)$user['password_hash'])) throw new AccountCredentialsException();
            if (!hash_equals((string)$user['userid'], $confirmation)) {
                throw new ValidationException(['confirm_userid' => 'Enter the exact user ID to confirm deletion.']);
            }
            if ($user['role'] === 'admin' && $user['status'] === 'active' && count($admins) <= 1) {
                throw new LastAdministratorException();
            }
            $id = (int)$user['id'];
            // Admin change details may contain previous roles/project assignments.
            $audit = $this->pdo->prepare("DELETE FROM admin_audit_logs WHERE (target_type = 'user' AND target_id = :target) OR admin_user_id = :actor");
            $audit->execute([':target' => $id, ':actor' => $id]);
            // Remove identifiable rate-limit keys. Keep client-based abuse protection.
            $subjects = array_unique(array_filter([
                'userid:' . $user['userid_normalized'],
                $user['email_normalized'] === null ? null : 'email:' . $user['email_normalized'],
                'identity:' . $user['userid_normalized'],
                $user['email_normalized'] === null ? null : 'identity:' . $user['email_normalized'],
            ]));
            $removeLimit = $this->pdo->prepare('DELETE FROM auth_rate_limits WHERE subject_hash = :hash');
            foreach ($subjects as $subject) $removeLimit->execute([':hash' => hash_hmac('sha256', $subject, $this->secret)]);
            $delete = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute([':id' => $id]);
            // Existing foreign keys delete tokens/memberships and set attribution to NULL.
            // They retain activities and projects, including soft-deleted records.
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}

final class AccountCredentialsException extends RuntimeException {}
final class LastAdministratorException extends RuntimeException {}
