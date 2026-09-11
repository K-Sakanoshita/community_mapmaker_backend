<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\AuthService;
use CommunityMapMaker\Auth\AuthDisabledException;
use CommunityMapMaker\Auth\InvalidTokenException;
use CommunityMapMaker\Auth\Mailer;
use CommunityMapMaker\Auth\RateLimitExceededException;
use CommunityMapMaker\Auth\RateLimiterInterface;
use CommunityMapMaker\Auth\TokenRepositoryInterface;
use CommunityMapMaker\Auth\TransactionManagerInterface;
use CommunityMapMaker\Auth\UserRepositoryInterface;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/AuthService.php';
require_once dirname(__DIR__) . '/lib/RateLimiter.php';
require_once dirname(__DIR__) . '/lib/UserRepository.php';

final class MemoryUsers implements UserRepositoryInterface
{
    public array $rows = [];
    private int $nextId = 1;

    public function create(string $userid, string $useridNormalized, ?string $email, ?string $emailNormalized, string $passwordHash, string $status, string $role = 'user'): array
    {
        foreach ($this->rows as $row) {
            if ($row['userid_normalized'] === $useridNormalized || ($emailNormalized !== null && $row['email_normalized'] === $emailNormalized)) {
                throw new CommunityMapMaker\Auth\DuplicateIdentityException();
            }
        }
        $row = [
            'id' => $this->nextId++, 'userid' => $userid, 'userid_normalized' => $useridNormalized,
            'email' => $email, 'email_normalized' => $emailNormalized,
            'password_hash' => $passwordHash, 'status' => $status, 'role' => $role, 'email_verified_at' => null,
        ];
        $this->rows[$row['id']] = $row;
        return $row;
    }

    public function findById(int $id): ?array { return $this->rows[$id] ?? null; }

    public function findByIdentity(string $identityNormalized): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['userid_normalized'] === $identityNormalized || $row['email_normalized'] === $identityNormalized) return $row;
        }
        return null;
    }

    public function activate(int $id): void
    {
        if (($this->rows[$id]['status'] ?? '') === 'pending') {
            $this->rows[$id]['status'] = 'active';
            $this->rows[$id]['email_verified_at'] = gmdate('Y-m-d H:i:s');
        }
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->rows[$id]['password_hash'] = $passwordHash;
    }

    public function recordLogin(int $id): void { $this->rows[$id]['last_login_at'] = gmdate('Y-m-d H:i:s'); }
}

final class MemoryTokens implements TokenRepositoryInterface
{
    public array $rows = [];

    public function issue(int $userId, string $purpose, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->invalidate($userId, $purpose);
        $this->rows[] = ['user_id' => $userId, 'purpose' => $purpose, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt, 'used' => false];
    }

    public function consume(string $purpose, string $tokenHash, DateTimeImmutable $now): ?array
    {
        foreach ($this->rows as &$row) {
            if ($row['purpose'] === $purpose && hash_equals($row['token_hash'], $tokenHash) && !$row['used'] && $row['expires_at'] > $now) {
                $row['used'] = true;
                return $row;
            }
        }
        return null;
    }

    public function invalidate(int $userId, string $purpose): void
    {
        foreach ($this->rows as &$row) {
            if ($row['user_id'] === $userId && $row['purpose'] === $purpose) $row['used'] = true;
        }
    }
}

final class AllowingRateLimiter implements RateLimiterInterface
{
    public array $calls = [];
    public ?string $deniedAction = null;

    public function hit(string $action, string $subject, int $limit, int $windowSeconds): bool
    {
        $this->calls[] = compact('action', 'subject', 'limit', 'windowSeconds');
        return $action !== $this->deniedAction;
    }
}

final class ImmediateTransactions implements TransactionManagerInterface
{
    public function run(callable $callback): mixed { return $callback(); }
}

final class RecordingMailer implements Mailer
{
    public array $verification = [];
    public array $resets = [];
    public bool $succeeds = true;

    public function sendVerification(array $user, string $verificationUrl, DateTimeImmutable $expiresAt): bool
    {
        $this->verification[] = compact('user', 'verificationUrl', 'expiresAt');
        return $this->succeeds;
    }

    public function sendPasswordReset(array $user, string $resetUrl, DateTimeImmutable $expiresAt): bool
    {
        $this->resets[] = compact('user', 'resetUrl', 'expiresAt');
        return $this->succeeds;
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assertThrows(callable $callback, string $class, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertTrue($error instanceof $class, $message . ': got ' . $error::class);
        return;
    }
    throw new RuntimeException($message . ': no exception');
}

function tokenFromUrl(string $url): string
{
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    return (string)($query['token'] ?? '');
}

$users = new MemoryUsers();
$tokens = new MemoryTokens();
$limits = new AllowingRateLimiter();
$mailer = new RecordingMailer();
$config = [
    'registration_enabled' => true,
    'email_verification' => true,
    'verification_token_ttl_seconds' => 3600,
    'password_reset_token_ttl_seconds' => 900,
    'resend_cooldown_seconds' => 60,
    'password_min_length' => 8,
    'verification_url' => 'https://example.jp/auth/verify.php',
    'password_reset_url' => 'https://example.jp/reset-password.html',
    'rate_limits' => [
        'register_client' => ['limit' => 5, 'window_seconds' => 3600],
        'register_email' => ['limit' => 5, 'window_seconds' => 3600],
        'register_userid' => ['limit' => 5, 'window_seconds' => 3600],
        'resend_client' => ['limit' => 10, 'window_seconds' => 3600],
        'resend_email' => ['limit' => 5, 'window_seconds' => 3600],
        'reset_client' => ['limit' => 10, 'window_seconds' => 3600],
        'reset_email' => ['limit' => 5, 'window_seconds' => 3600],
    ],
];
$service = new AuthService($users, $tokens, $limits, new ImmediateTransactions(), $mailer, $config);

$registration = $service->register([
    'userid' => 'Test.User', 'email' => 'Test.User@Example.JP',
    'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
], '192.0.2.1');
assertTrue($registration['status'] === 'pending', 'Registration must create a pending user.');
assertTrue($registration['verification_email_sent'] === true, 'Registration must report successful delivery.');
assertTrue($users->rows[1]['userid_normalized'] === 'test.user', 'User ID must be normalized.');
assertTrue($users->rows[1]['email_normalized'] === 'test.user@example.jp', 'Email must be normalized.');
assertTrue($users->rows[1]['password_hash'] !== 'correct horse battery staple', 'Password must not be stored as plaintext.');
assertTrue(password_verify('correct horse battery staple', $users->rows[1]['password_hash']), 'Stored password hash must verify.');
assertTrue($service->authenticate('test.user', 'correct horse battery staple') === null, 'Pending user must not authenticate.');

$verificationToken = tokenFromUrl($mailer->verification[0]['verificationUrl']);
assertTrue(strlen($verificationToken) === 64, 'Verification URL must contain a random token.');
assertTrue($tokens->rows[0]['token_hash'] !== $verificationToken, 'Raw verification token must not be stored.');
$service->verifyEmail($verificationToken);
assertTrue($users->rows[1]['status'] === 'active', 'Verified user must become active.');
assertTrue($service->authenticate('TEST.USER', 'correct horse battery staple') !== null, 'Active user must authenticate case-insensitively.');
assertThrows(fn() => $service->verifyEmail($verificationToken), InvalidTokenException::class, 'Used verification token must be rejected.');

$unknownReset = $service->requestPasswordReset(['email' => 'unknown@example.jp'], '192.0.2.2');
assertTrue($unknownReset === ['accepted' => true, 'reset_email_sent' => true], 'Unknown email must receive a generic result.');
assertTrue(count($mailer->resets) === 0, 'Unknown email must not send mail.');

$service->requestPasswordReset(['email' => 'test.user@example.jp'], '192.0.2.2');
assertTrue(count($mailer->resets) === 1, 'Active user must receive reset mail.');
$resetToken = tokenFromUrl($mailer->resets[0]['resetUrl']);
$service->resetPassword([
    'token' => $resetToken, 'password' => 'new correct horse battery', 'password_confirmation' => 'new correct horse battery',
]);
assertTrue($service->authenticate('test.user', 'correct horse battery staple') === null, 'Old password must stop working.');
assertTrue($service->authenticate('test.user', 'new correct horse battery') !== null, 'New password must authenticate.');
assertThrows(fn() => $service->resetPassword([
    'token' => $resetToken, 'password' => 'another correct password', 'password_confirmation' => 'another correct password',
]), InvalidTokenException::class, 'Used reset token must be rejected.');

$limitedUsers = new MemoryUsers();
$limitedTokens = new MemoryTokens();
$limitedRates = new AllowingRateLimiter();
$limitedRates->deniedAction = 'register_client';
$limitedService = new AuthService($limitedUsers, $limitedTokens, $limitedRates, new ImmediateTransactions(), new RecordingMailer(), $config);
assertThrows(fn() => $limitedService->register([
    'userid' => 'limited-user', 'email' => 'limited@example.jp',
    'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
], '192.0.2.9'), RateLimitExceededException::class, 'Registration rate limit must be enforced.');
assertTrue($limitedUsers->rows === [], 'Rate-limited registration must not create a user.');

$directUsers = new MemoryUsers();
$directMailer = new RecordingMailer();
$directConfig = $config;
$directConfig['email_verification'] = false;
$directService = new AuthService($directUsers, new MemoryTokens(), new AllowingRateLimiter(), new ImmediateTransactions(), $directMailer, $directConfig);
$directResult = $directService->register([
    'userid' => 'direct-user', 'email' => 'direct@example.jp',
    'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
], '192.0.2.10');
assertTrue($directResult['status'] === 'active', 'Registration without required verification must create an active user.');
assertTrue($directResult['verification_required'] === false, 'Optional verification must be reported.');
assertTrue($directMailer->verification === [], 'Registration without required verification must not send verification mail.');
assertTrue($directService->authenticate('direct-user', 'correct horse battery staple') !== null, 'Directly active user must authenticate.');

$disabledConfig = $config;
$disabledConfig['registration_enabled'] = false;
$disabledService = new AuthService(new MemoryUsers(), new MemoryTokens(), new AllowingRateLimiter(), new ImmediateTransactions(), new RecordingMailer(), $disabledConfig);
assertThrows(fn() => $disabledService->register([
    'userid' => 'disabled-user', 'email' => 'disabled@example.jp',
    'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
], '192.0.2.11'), AuthDisabledException::class, 'Disabled registration must reject new accounts.');

$failedMailUsers = new MemoryUsers();
$failedMailer = new RecordingMailer();
$failedMailer->succeeds = false;
$failedMailService = new AuthService($failedMailUsers, new MemoryTokens(), new AllowingRateLimiter(), new ImmediateTransactions(), $failedMailer, $config);
$failedMailResult = $failedMailService->register([
    'userid' => 'mail-failure', 'email' => 'mail-failure@example.jp',
    'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
], '192.0.2.12');
assertTrue($failedMailResult['verification_email_sent'] === false, 'Mail delivery failure must be reported.');
assertTrue($failedMailUsers->rows[1]['status'] === 'pending', 'Mail delivery failure must keep the account pending for resend.');

foreach (['1234567', 'あいうえおかき'] as $short) {
    assertThrows(fn() => $directService->register([
        'userid' => 'short-user', 'email' => 'short@example.jp',
        'password' => $short, 'password_confirmation' => $short,
    ], '192.0.2.12'), CommunityMapMaker\Auth\ValidationException::class, 'Seven characters must be rejected.');
}
foreach (['12345678', 'あいうえおかきく'] as $index => $valid) {
    $directService->register([
        'userid' => 'eight-user-' . $index, 'email' => 'eight' . $index . '@example.jp',
        'password' => $valid, 'password_confirmation' => $valid,
    ], '192.0.2.12');
    assertTrue($directService->authenticate('eight-user-' . $index, $valid) !== null, 'Eight characters must authenticate.');
}
echo "AuthService behavior: ok\n";
