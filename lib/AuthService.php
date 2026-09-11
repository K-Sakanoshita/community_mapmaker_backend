<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AuthService
{
    private const VERIFY_EMAIL = 'verify_email';
    private const RESET_PASSWORD = 'reset_password';

    public function __construct(
        private UserRepositoryInterface $users,
        private TokenRepositoryInterface $tokens,
        private RateLimiterInterface $rateLimiter,
        private TransactionManagerInterface $transactions,
        private Mailer $mailer,
        private array $config
    ) {
    }

    public function register(array $input, string $clientIdentifier): array
    {
        if (($this->config['registration_enabled'] ?? false) !== true) {
            throw new AuthDisabledException('Registration is disabled.');
        }

        [$userid, $useridNormalized] = $this->validateUserid($input['userid'] ?? null);
        [$email, $emailNormalized] = $this->validateEmail($input['email'] ?? null);
        $password = $this->validatePassword($input['password'] ?? null, $input['password_confirmation'] ?? null);

        $this->enforceLimit('register_client', 'client:' . $clientIdentifier);
        $this->enforceLimit('register_email', 'email:' . $emailNormalized);
        $this->enforceLimit('register_userid', 'userid:' . $useridNormalized);

        $requiresVerification = ($this->config['email_verification'] ?? true) === true;
        $status = $requiresVerification ? 'pending' : 'active';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) throw new RuntimeException('Password hashing failed.');

        $tokenData = null;
        $user = $this->transactions->run(function () use ($userid, $useridNormalized, $email, $emailNormalized, $passwordHash, $status, $requiresVerification, &$tokenData): array {
            $created = $this->users->create($userid, $useridNormalized, $email, $emailNormalized, $passwordHash, $status);
            if ($requiresVerification) {
                $tokenData = $this->issueToken((int)$created['id'], self::VERIFY_EMAIL, $this->verificationTtl());
            }
            return $created;
        });

        $sent = true;
        if ($requiresVerification && $tokenData !== null) {
            $sent = $this->mailer->sendVerification(
                $user,
                $this->tokenUrl('verification_url', $tokenData['token']),
                $tokenData['expires_at']
            );
        }

        return [
            'status' => $status,
            'verification_required' => $requiresVerification,
            'verification_email_sent' => $sent,
        ];
    }

    public function verifyEmail(string $rawToken): void
    {
        $tokenHash = $this->validateToken($rawToken);
        $this->transactions->run(function () use ($tokenHash): void {
            $token = $this->tokens->consume(self::VERIFY_EMAIL, $tokenHash, $this->now());
            if ($token === null) throw new InvalidTokenException('The verification token is invalid or expired.');
            $this->users->activate((int)$token['user_id']);
        });
    }

    public function resendVerification(array $input, string $clientIdentifier): array
    {
        [, $identityNormalized] = $this->validateIdentity($input['identity'] ?? null);
        $this->enforceLimit('resend_client', 'client:' . $clientIdentifier);
        $user = $this->users->findByIdentity($identityNormalized);
        $rateLimitIdentity = (string)($user['email_normalized'] ?? $identityNormalized);
        $this->enforceDirectLimit('resend_cooldown', 'identity:' . $rateLimitIdentity, 1, $this->resendCooldown());
        $this->enforceLimit('resend_email', 'identity:' . $rateLimitIdentity);

        if ($user === null || (string)$user['status'] !== 'pending') {
            return ['accepted' => true, 'verification_email_sent' => true];
        }

        $tokenData = $this->transactions->run(fn(): array => $this->issueToken((int)$user['id'], self::VERIFY_EMAIL, $this->verificationTtl()));
        $sent = $this->mailer->sendVerification(
            $user,
            $this->tokenUrl('verification_url', $tokenData['token']),
            $tokenData['expires_at']
        );
        return ['accepted' => true, 'verification_email_sent' => $sent];
    }

    public function requestPasswordReset(array $input, string $clientIdentifier): array
    {
        [, $emailNormalized] = $this->validateEmail($input['email'] ?? null);
        $this->enforceLimit('reset_client', 'client:' . $clientIdentifier);
        $this->enforceLimit('reset_email', 'email:' . $emailNormalized);

        $user = $this->users->findByIdentity($emailNormalized);
        if ($user === null || (string)$user['status'] !== 'active') {
            return ['accepted' => true, 'reset_email_sent' => true];
        }

        $tokenData = $this->transactions->run(fn(): array => $this->issueToken((int)$user['id'], self::RESET_PASSWORD, $this->passwordResetTtl()));
        $sent = $this->mailer->sendPasswordReset(
            $user,
            $this->tokenUrl('password_reset_url', $tokenData['token']),
            $tokenData['expires_at']
        );
        return ['accepted' => true, 'reset_email_sent' => $sent];
    }

    public function resetPassword(array $input): void
    {
        $tokenHash = $this->validateToken((string)($input['token'] ?? ''));
        $password = $this->validatePassword($input['password'] ?? null, $input['password_confirmation'] ?? null);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) throw new RuntimeException('Password hashing failed.');

        $this->transactions->run(function () use ($tokenHash, $passwordHash): void {
            $token = $this->tokens->consume(self::RESET_PASSWORD, $tokenHash, $this->now());
            if ($token === null) throw new InvalidTokenException('The reset token is invalid or expired.');
            $this->users->updatePassword((int)$token['user_id'], $passwordHash);
            $this->users->activate((int)$token['user_id']);
            $this->tokens->invalidate((int)$token['user_id'], self::RESET_PASSWORD);
        });
    }

    public function inviteUser(array $input, string $clientIdentifier): array
    {
        [$userid, $useridNormalized] = $this->validateUserid($input['userid'] ?? null);
        [$email, $emailNormalized] = $this->validateEmail($input['email'] ?? null);
        $role = $this->validateRole($input['role'] ?? 'user');
        $this->enforceLimit('reset_client', 'client:' . $clientIdentifier);
        $this->enforceLimit('reset_email', 'email:' . $emailNormalized);

        $temporaryPassword = bin2hex(random_bytes(32));
        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) throw new RuntimeException('Password hashing failed.');

        $tokenData = null;
        $user = $this->transactions->run(function () use ($userid, $useridNormalized, $email, $emailNormalized, $passwordHash, $role, &$tokenData): array {
            $created = $this->users->create($userid, $useridNormalized, $email, $emailNormalized, $passwordHash, 'pending', $role);
            $tokenData = $this->issueToken((int)$created['id'], self::RESET_PASSWORD, $this->passwordResetTtl());
            return $created;
        });
        $sent = $this->mailer->sendPasswordReset(
            $user,
            $this->tokenUrl('password_reset_url', $tokenData['token']),
            $tokenData['expires_at']
        );
        return ['user_id' => (int)$user['id'], 'password_setup_email_sent' => $sent];
    }

    public function createManagedUserWithoutEmail(array $input): array
    {
        [$userid, $useridNormalized] = $this->validateUserid($input['userid'] ?? null);
        $role = $this->validateRole($input['role'] ?? 'user');
        $password = $this->validatePassword($input['password'] ?? null, $input['password_confirmation'] ?? null);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) throw new RuntimeException('Password hashing failed.');

        $user = $this->transactions->run(fn(): array => $this->users->create(
            $userid,
            $useridNormalized,
            null,
            null,
            $passwordHash,
            'active',
            $role
        ));
        return ['user_id' => (int)$user['id']];
    }

    public function setPasswordForUser(int $userId, array $input): void
    {
        if ($this->users->findById($userId) === null) {
            throw new ValidationException(['user' => 'User was not found.']);
        }
        $password = $this->validatePassword($input['password'] ?? null, $input['password_confirmation'] ?? null);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) throw new RuntimeException('Password hashing failed.');
        $this->transactions->run(function () use ($userId, $passwordHash): void {
            $this->users->updatePassword($userId, $passwordHash);
        });
    }

    public function resendVerificationForUser(int $userId, string $clientIdentifier): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) throw new ValidationException(['user' => 'User was not found.']);
        if (($user['email_normalized'] ?? null) === null || (string)$user['email_normalized'] === '') {
            throw new ValidationException(['email' => 'This user does not have an email address.']);
        }
        if ((string)$user['status'] !== 'pending') {
            throw new ValidationException(['status' => 'Verification can only be resent to pending users.']);
        }
        $this->enforceLimit('resend_client', 'client:' . $clientIdentifier);
        $identity = (string)$user['email_normalized'];
        $this->enforceDirectLimit('resend_cooldown', 'identity:' . $identity, 1, $this->resendCooldown());
        $this->enforceLimit('resend_email', 'identity:' . $identity);
        $tokenData = $this->transactions->run(fn(): array => $this->issueToken($userId, self::VERIFY_EMAIL, $this->verificationTtl()));
        $sent = $this->mailer->sendVerification(
            $user,
            $this->tokenUrl('verification_url', $tokenData['token']),
            $tokenData['expires_at']
        );
        return ['verification_email_sent' => $sent];
    }

    public function sendPasswordResetForUser(int $userId, string $clientIdentifier): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) throw new ValidationException(['user' => 'User was not found.']);
        if (($user['email_normalized'] ?? null) === null || (string)$user['email_normalized'] === '') {
            throw new ValidationException(['email' => 'This user does not have an email address.']);
        }
        if (!in_array((string)$user['status'], ['active', 'pending'], true)) {
            throw new ValidationException(['status' => 'Password reset cannot be sent to a disabled user.']);
        }
        $this->enforceLimit('reset_client', 'client:' . $clientIdentifier);
        $this->enforceLimit('reset_email', 'email:' . (string)$user['email_normalized']);
        $tokenData = $this->transactions->run(fn(): array => $this->issueToken($userId, self::RESET_PASSWORD, $this->passwordResetTtl()));
        $sent = $this->mailer->sendPasswordReset(
            $user,
            $this->tokenUrl('password_reset_url', $tokenData['token']),
            $tokenData['expires_at']
        );
        return ['reset_email_sent' => $sent];
    }

    public function authenticate(string $identity, string $password): ?array
    {
        [, $normalized] = $this->validateIdentity($identity);
        $user = $this->users->findByIdentity($normalized);
        if ($user === null || (string)$user['status'] !== 'active') return null;
        if (!password_verify($password, (string)$user['password_hash'])) return null;
        $this->users->recordLogin((int)$user['id']);
        return [
            'id' => (int)$user['id'],
            'userid' => (string)$user['userid'],
            'status' => 'active',
            'role' => (string)($user['role'] ?? 'user'),
        ];
    }

    private function validateUserid(mixed $value): array
    {
        $userid = trim((string)$value);
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,63}\z/', $userid)) {
            throw new ValidationException(['userid' => 'User ID must be 3-64 characters using letters, numbers, dot, underscore, or hyphen.']);
        }
        return [$userid, mb_strtolower($userid, 'UTF-8')];
    }

    private function validateEmail(mixed $value): array
    {
        $email = trim((string)$value);
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'A valid email address is required.']);
        }
        return [$email, mb_strtolower($email, 'UTF-8')];
    }

    private function validateRole(mixed $value): string
    {
        $role = trim((string)$value);
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new ValidationException(['role' => 'Role must be user or admin.']);
        }
        return $role;
    }

    private function validateIdentity(mixed $value): array
    {
        $identity = trim((string)$value);
        if ($identity === '' || strlen($identity) > 255 || preg_match('/[\r\n]/', $identity)) {
            throw new ValidationException(['identity' => 'A valid user ID or email address is required.']);
        }
        return [$identity, mb_strtolower($identity, 'UTF-8')];
    }

    private function validatePassword(mixed $passwordValue, mixed $confirmationValue): string
    {
        $password = (string)$passwordValue;
        $confirmation = (string)$confirmationValue;
        $minimum = max(8, (int)($this->config['password_min_length'] ?? 8));
        $errors = [];
        if (mb_strlen($password, 'UTF-8') < $minimum || strlen($password) > 4096) {
            $errors['password'] = sprintf('Password must contain at least %d characters and at most 4096 bytes.', $minimum);
        }
        if (!hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'Password confirmation does not match.';
        }
        if ($errors) throw new ValidationException($errors);
        return $password;
    }

    private function validateToken(string $rawToken): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $rawToken)) {
            throw new InvalidTokenException('The token is invalid.');
        }
        return hash('sha256', $rawToken);
    }

    private function issueToken(int $userId, string $purpose, int $ttlSeconds): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = $this->now()->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $this->tokens->issue($userId, $purpose, hash('sha256', $rawToken), $expiresAt);
        return ['token' => $rawToken, 'expires_at' => $expiresAt];
    }

    private function tokenUrl(string $configKey, string $token): string
    {
        $url = (string)($this->config[$configKey] ?? '');
        $allowLocal = ($this->config['allow_insecure_local_urls'] ?? false) === true
            && (bool)preg_match('#\Ahttp://(?:127\.0\.0\.1|localhost)(?::\d+)?/#', $url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || (!str_starts_with($url, 'https://') && !$allowLocal)) {
            throw new RuntimeException(sprintf('auth.%s must be an HTTPS URL.', $configKey));
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    }

    private function enforceLimit(string $name, string $subject): void
    {
        $rule = $this->config['rate_limits'][$name] ?? null;
        if (!is_array($rule)) throw new RuntimeException(sprintf('Missing rate limit rule: %s', $name));
        $this->enforceDirectLimit($name, $subject, (int)($rule['limit'] ?? 0), (int)($rule['window_seconds'] ?? 0));
    }

    private function enforceDirectLimit(string $name, string $subject, int $limit, int $windowSeconds): void
    {
        if ($limit < 1 || $windowSeconds < 1) throw new RuntimeException(sprintf('Invalid rate limit rule: %s', $name));
        if (!$this->rateLimiter->hit($name, $subject, $limit, $windowSeconds)) {
            throw new RateLimitExceededException('Too many requests.');
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function verificationTtl(): int
    {
        return max(300, (int)($this->config['verification_token_ttl_seconds'] ?? 86400));
    }

    private function passwordResetTtl(): int
    {
        return max(300, (int)($this->config['password_reset_token_ttl_seconds'] ?? 3600));
    }

    private function resendCooldown(): int
    {
        return max(1, (int)($this->config['resend_cooldown_seconds'] ?? 60));
    }
}

final class ValidationException extends RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct('Input validation failed.');
    }
}

final class AuthDisabledException extends RuntimeException
{
}

final class InvalidTokenException extends RuntimeException
{
}
