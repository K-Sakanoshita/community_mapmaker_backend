<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/NativeMailer.php';

// Capture calls without sending real mail.
function mail(string $to, string $subject, string $body, string $headers, string $parameters): bool
{
    $GLOBALS['captured_mail'][] = compact('to', 'subject', 'body', 'headers', 'parameters');
    return $GLOBALS['mail_result'] ?? true;
}

function mailAssert(bool $ok, string $message): void
{
    if (!$ok) throw new \RuntimeException($message);
}

$GLOBALS['captured_mail'] = [];
$mailer = new NativeMailer(['from_address' => 'sender@example.jp', 'from_name' => '地図']);
$user = ['userid' => 'test-user', 'email' => 'recipient@example.com', 'status' => 'pending'];
$expires = new \DateTimeImmutable('2026-10-08 13:00:00', new \DateTimeZone('UTC'));
mailAssert($mailer->sendVerification($user, 'https://example.jp/verify?token=test', $expires), 'Verification must report mail acceptance.');
mailAssert($mailer->sendPasswordReset($user, 'https://example.jp/reset?token=test', $expires), 'Password reset must report mail acceptance.');
mailAssert($mailer->sendRegistrationNotice('admin@example.jp', $user), 'Admin notice must report mail acceptance.');
foreach ($GLOBALS['captured_mail'] as $sent) {
    mailAssert($sent['parameters'] === '-fsender@example.jp', 'Envelope sender must match the configured From address.');
    mailAssert(str_contains($sent['headers'], '<sender@example.jp>'), 'Visible From must match envelope sender.');
    mailAssert(str_contains($sent['headers'], "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8"), 'UTF-8 mail must declare MIME-Version and content type.');
}
mailAssert(str_contains($GLOBALS['captured_mail'][0]['body'], 'メールアドレス確認'), 'Verification body must remain UTF-8.');
$GLOBALS['mail_result'] = false;
mailAssert(!$mailer->sendVerification($user, 'https://example.jp/verify', $expires), 'Transport failure must propagate.');
$count = count($GLOBALS['captured_mail']);
mailAssert(!$mailer->sendRegistrationNotice("invalid\r\nBcc: attacker@example.jp", $user), 'Invalid recipients must be rejected.');
mailAssert(count($GLOBALS['captured_mail']) === $count, 'Rejected recipients must not invoke transport.');
foreach (["sender@example.jp\r\nBcc: attacker@example.jp", 'sender@example.jp -X/tmp/mail-log', '"sender;id"@example.jp', "sender'quoted@example.jp"] as $address) {
    try {
        new NativeMailer(['from_address' => $address]);
        throw new \RuntimeException('Unsafe sendmail argument was accepted.');
    } catch (\InvalidArgumentException) {}
}
new NativeMailer(['from_address' => 'sender+notifications@example.jp']);
echo "Native mail envelope, MIME, and argument safety: ok\n";
