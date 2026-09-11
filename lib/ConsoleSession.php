<?php
declare(strict_types=1);
namespace CommunityMapMaker\Auth;

final class ConsoleSession
{
    private static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('cmm_console');
        $started = session_start([
            'use_strict_mode' => 1, 'use_only_cookies' => 1,
            'cookie_lifetime' => 0, 'cookie_httponly' => true,
            'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'cookie_samesite' => 'Strict',
            'cookie_path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
            'gc_maxlifetime' => 86400,
        ]);
        if (!$started) throw new \RuntimeException('Console session storage is unavailable.');
    }

    public static function establish(array $user): void
    {
        self::start();
        if (!session_regenerate_id(true)) throw new \RuntimeException('Console session rotation failed.');
        $_SESSION = ['user_id' => (int)$user['id'], 'password_stamp' => hash('sha256', $user['password_hash'])];
        session_write_close();
    }

    public static function user(array $container): ?array
    {
        // This non-simple header prevents cross-site form requests using cookies.
        // It is deliberately not included in the cross-origin allow-headers list.
        if (($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1' || !isset($_COOKIE['cmm_console'])) return null;
        self::start();
        $id = (int)($_SESSION['user_id'] ?? 0);
        $stamp = (string)($_SESSION['password_stamp'] ?? '');
        session_write_close();
        $user = $container['user_repo']->findById($id);
        if (!$user || $user['status'] !== 'active' || !hash_equals($stamp, hash('sha256', $user['password_hash']))) return null;
        return $user;
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        $params = session_get_cookie_params();
        session_destroy();
        setcookie('cmm_console', '', ['expires' => time() - 3600, 'path' => $params['path'],
            'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
}
