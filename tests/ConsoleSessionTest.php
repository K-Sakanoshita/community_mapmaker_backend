<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/ConsoleSession.php';
use CommunityMapMaker\Auth\ConsoleSession;

function checkSession(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$_SERVER['SCRIPT_NAME'] = '/api/console-session.php';
$_SERVER['HTTP_X_CONSOLE_SESSION'] = '1';
$repo = new class {
    public array $user = ['id' => 1, 'status' => 'active', 'role' => 'admin', 'password_hash' => 'hash-one'];
    public function findById(int $id): ?array { return $id === 1 ? $this->user : null; }
};
$container = ['user_repo' => $repo];
ConsoleSession::establish($repo->user);
$_COOKIE['cmm_console'] = session_id();
checkSession(session_get_cookie_params()['lifetime'] === 0, 'Must use a browser session cookie.');
checkSession(session_get_cookie_params()['httponly'], 'Cookie must be HttpOnly.');
checkSession(ConsoleSession::user($container)['id'] === 1, 'A subsequent request must restore the user.');
unset($_SERVER['HTTP_X_CONSOLE_SESSION']);
checkSession(ConsoleSession::user($container) === null, 'Requests without the CSRF guard must be rejected.');
$_SERVER['HTTP_X_CONSOLE_SESSION'] = '1';
$repo->user['status'] = 'disabled';
checkSession(ConsoleSession::user($container) === null, 'Disabled users must lose access.');
$repo->user['status'] = 'active';
$repo->user['password_hash'] = 'hash-two';
checkSession(ConsoleSession::user($container) === null, 'Password changes must invalidate old sessions.');
ConsoleSession::destroy();
checkSession(ConsoleSession::user($container) === null, 'Logout must invalidate the session.');
ConsoleSession::destroy();
echo "Console session behavior: ok\n";
