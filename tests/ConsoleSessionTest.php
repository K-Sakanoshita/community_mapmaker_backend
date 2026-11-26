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
$repo->user['role'] = 'contributor';
checkSession(ConsoleSession::user($container) === null, 'Changing to contributor must revoke an existing console session.');
try {
    ConsoleSession::establish($repo->user);
    throw new LogicException('Contributor must not establish a console session.');
} catch (RuntimeException $error) {
    checkSession($error->getMessage() === 'Console access is not permitted.', 'Contributor sessions must be rejected explicitly.');
}
$repo->user['role'] = 'admin';
unset($_SERVER['HTTP_X_CONSOLE_SESSION']);
checkSession(ConsoleSession::user($container) === null, 'Requests without the CSRF guard must be rejected.');
$_SERVER['HTTP_X_CONSOLE_SESSION'] = '1';
$repo->user['status'] = 'disabled';
checkSession(ConsoleSession::user($container) === null, 'Disabled users must lose access.');
$repo->user['status'] = 'active';
$repo->user['updated_at'] = '2026-10-24 10:00:01';
checkSession(ConsoleSession::user($container) === null, 'Account updates invalidate pre-deletion sessions after restoration.');
ConsoleSession::establish($repo->user);
$_COOKIE['cmm_console'] = session_id();
checkSession(ConsoleSession::user($container) !== null, 'Updated users can establish fresh sessions.');
$flow = ['state' => 'expected-state', 'expires_at' => time() + 60];
ConsoleSession::beginOsmAuthorization($flow);
checkSession(ConsoleSession::consumeOsmAuthorization('wrong-state') === null, 'OSM callbacks must match the pending state.');
checkSession(ConsoleSession::consumeOsmAuthorization('expected-state') === $flow, 'A valid callback must recover the pending flow.');
checkSession(ConsoleSession::consumeOsmAuthorization('expected-state') === null, 'OSM callbacks must be single use.');
ConsoleSession::beginOsmAuthorization(['state' => 'expired', 'expires_at' => time() - 1]);
checkSession(ConsoleSession::consumeOsmAuthorization('expired') === null, 'An expired OSM flow must be rejected.');
$repo->user['password_hash'] = 'hash-two';
checkSession(ConsoleSession::user($container) === null, 'Password changes must invalidate old sessions.');
ConsoleSession::beginOsmAuthorization($flow);
ConsoleSession::destroy();
checkSession(ConsoleSession::consumeOsmAuthorization('expected-state') === null, 'Logout must discard a pending OSM flow.');
checkSession(ConsoleSession::user($container) === null, 'Logout must invalidate the session.');
ConsoleSession::destroy();
echo "Console session behavior: ok\n";
