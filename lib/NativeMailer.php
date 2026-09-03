<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use DateTimeImmutable;
use InvalidArgumentException;

final class NativeMailer implements Mailer
{
    private string $fromAddress;
    private string $fromName;
    private string $siteName;

    public function __construct(array $config)
    {
        $this->fromAddress = trim((string)($config['from_address'] ?? ''));
        $this->fromName = $this->singleLine((string)($config['from_name'] ?? 'Community Map Maker'));
        $this->siteName = $this->singleLine((string)($config['site_name'] ?? 'Community Map Maker'));
        if (!filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('mail.from_address is invalid.');
        }
    }

    public function sendVerification(array $user, string $verificationUrl, DateTimeImmutable $expiresAt): bool
    {
        $subject = sprintf('[%s] メールアドレスの確認', $this->siteName);
        $body = sprintf(
            "%s へのユーザー登録を受け付けました。\n\nユーザーID: %s\n確認URL: %s\n有効期限: %s UTC\n\n心当たりがない場合は、このメールを無視してください。\nパスワードをメールでお知らせすることはありません。\n",
            $this->siteName,
            $this->singleLine((string)$user['userid']),
            $verificationUrl,
            $expiresAt->format('Y-m-d H:i:s')
        );
        return $this->send((string)$user['email'], $subject, $body);
    }

    public function sendPasswordReset(array $user, string $resetUrl, DateTimeImmutable $expiresAt): bool
    {
        $subject = sprintf('[%s] パスワード再設定', $this->siteName);
        $body = sprintf(
            "%s のパスワード再設定が要求されました。\n\nユーザーID: %s\n再設定URL: %s\n有効期限: %s UTC\n\n心当たりがない場合は、このメールを無視してください。\nパスワードそのものをメールでお知らせすることはありません。\n",
            $this->siteName,
            $this->singleLine((string)$user['userid']),
            $resetUrl,
            $expiresAt->format('Y-m-d H:i:s')
        );
        return $this->send((string)$user['email'], $subject, $body);
    }

    private function send(string $recipient, string $subject, string $body): bool
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return false;
        $headers = [
            'From: ' . sprintf('%s <%s>', mb_encode_mimeheader($this->fromName), $this->fromAddress),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return mail($recipient, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));
    }

    private function singleLine(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
