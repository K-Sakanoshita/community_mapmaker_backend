<?php
declare(strict_types=1);
// Local-only HTTP/Mailpit flow. Fixtures are unique and removed in finally.
if (getenv('CMM_LOCAL_REGISTRATION_FLOW') !== '1') throw new RuntimeException('Run in the local web container with CMM_LOCAL_REGISTRATION_FLOW=1.');
$config = require dirname(__DIR__) . '/docker/local/config.php';
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$request = static function (string $path, string $method = 'GET', ?array $body = null, array $headers = [], ?array $credentials = null): array {
    $curl = curl_init(str_starts_with($path, 'http://mailpit:8025/') ? $path : 'http://127.0.0.1:8080/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $body === null ? [] : ['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    if ($credentials !== null) curl_setopt($curl, CURLOPT_USERPWD, implode(':', $credentials));
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($raw, true), $raw];
};
$suffix = bin2hex(random_bytes(5));
$app = 'flow-' . $suffix;
$ownedApp = $app . '-owned';
$userid = 'flowuser-' . $suffix;
$coadminUserid = 'flowadmin-' . $suffix;
$email = $userid . '@example.test';
$password = 'LocalFlowPass123!';
$messageIds = [];
try {
    $insert = $pdo->prepare("INSERT INTO projects (app_key, project_name, frontend_url, frontend_public, recruiting, enabled, schema_json, created_at, updated_at) VALUES (?, ?, 'https://example.test/map', ?, 1, ?, '{\"fields\":{}}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $insert->execute([$app, '検証用の地図', 1, 1]);
    $insert->execute([$app . '-private', '非公開', 0, 1]);
    $insert->execute([$app . '-disabled', '無効', 1, 0]);
    [$status, $listing] = $request('auth/registration-projects.php');
    $listed = array_column($listing['projects'], null, 'app_key');
    $check($status === 200 && $listed[$app]['frontend_url'] === 'https://example.test/map', 'Public managed frontend URL must be returned');
    $check(!isset($listed[$app . '-private']) && !isset($listed[$app . '-disabled']), 'Private and disabled projects must not be listed');
    $input = ['userid' => $userid, 'email' => $email, 'password' => $password, 'password_confirmation' => $password, 'app_key' => $app, 'role' => 'admin'];
    [$status] = $request('auth/register.php', 'POST', array_replace($input, ['app_key' => $app . '-private']));
    $check($status === 422, 'Registration into a private project must be rejected');
    [$status, $registered] = $request('auth/register.php', 'POST', $input);
    $check($status === 201 && $registered['verification_required'] && $registered['verification_email_sent'], 'Registration and local mail delivery');
    $find = $pdo->prepare('SELECT * FROM users WHERE userid = ?');
    $find->execute([$userid]);
    $user = $find->fetch();
    $check($user['status'] === 'pending' && $user['role'] === 'user', 'Server must enforce pending regular user role');
    [$status] = $request('api/projects.php', 'POST', ['app_key' => $ownedApp, 'project_name' => 'Before verification'], ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 401, 'Pending registration must not create projects');
    $mailToken = static function () use ($request, $email, &$messageIds, $check): array {
        [$status, $messages] = $request('http://mailpit:8025/api/v1/messages?limit=100');
        $check($status === 200, 'Mailpit list');
        foreach ($messages['messages'] as $message) {
            foreach ($message['To'] as $to) {
                if ($to['Address'] !== $email) continue;
                [$status, $detail] = $request('http://mailpit:8025/api/v1/message/' . $message['ID']);
                $messageIds[] = $message['ID'];
                if (preg_match('/token=([a-f0-9]{64})(?:&app_key=([^\s]+))?/', $detail['Text'], $matches)) return [$matches[1], isset($matches[2]) ? rawurldecode($matches[2]) : ''];
            }
        }
        throw new RuntimeException('No verification mail for disposable user');
    };
    [$oldToken, $mailApp] = $mailToken();
    $check($mailApp === $app, 'Verification email must retain selected project');
    [$status, $resend] = $request('auth/resend-verification.php', 'POST', ['identity' => $email]);
    $check($status === 202 && $resend['verification_email_sent'], 'Resend must deliver locally');
    [$token, $resendApp] = $mailToken();
    $check($token !== $oldToken && $resendApp === $app, 'Resend must retain project and rotate token');
    [$status] = $request('auth/verify.php?token=' . $oldToken);
    $check($status === 400, 'Old token must be invalid after resend');
    [$status, $verified] = $request('auth/verify.php?token=' . $token);
    $check($status === 200 && $verified['email_verified'] && $verified['projects'][0]['app_key'] === $app, 'Verification must return persisted public project');
    [$status, $session] = $request('api/console-session.php', 'POST', null, ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 200 && $session['user']['role'] === 'user', 'Verified new user can enter the project console');
    [$status, $created] = $request('api/projects.php', 'POST', ['app_key' => $ownedApp, 'project_name' => 'My own map', 'schema' => ['fields' => []]], ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 201 && $created['access_role'] === 'project_admin' && (int)$created['created_by_user_id'] === (int)$user['id'], 'New user must own and manage their created project');
    $membership = $pdo->prepare('SELECT role FROM user_projects WHERE user_id = ? AND project_id = ?');
    $membership->execute([$user['id'], $created['id']]);
    $check($membership->fetchColumn() === 'project_admin', 'Project creation must persist project-admin membership');
    [$status, $visible] = $request('api/projects.php', 'GET', null, ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 200 && array_column($visible, 'app_key') === [$ownedApp], 'Console exposes owned project but hides contributor-only and unassigned projects');
    [$status] = $request('api/projects.php?app=' . $ownedApp, 'PUT', ['project_name' => 'Updated own map'], ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 200, 'New user can update their own project');
    [$status] = $request('api/projects.php?app=' . $app, 'PUT', ['project_name' => 'Must not change'], ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 403, 'Joining as contributor must not grant management of another project');
    [$status] = $request('api/projects.php?app=' . $app . '-private', 'PUT', ['project_name' => 'Must not change'], ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 403, 'Unassigned projects must not be manageable');
    [$status] = $request('api/admin-users.php', 'GET', null, ['X-Console-Session: 1'], [$userid, $password]);
    $check($status === 403, 'New user must not access global user administration');
    $insertCoadmin = $pdo->prepare("INSERT INTO users (userid,userid_normalized,password_hash,status,role,created_at,updated_at) VALUES (?,?,?,'active','user',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $insertCoadmin->execute([$coadminUserid,$coadminUserid,password_hash($password,PASSWORD_DEFAULT)]);
    [$status, $admins] = $request('api/project-administrators.php?app=' . $ownedApp, 'POST', ['userid' => $coadminUserid, 'role' => 'admin'], ['X-Console-Session: 1'], [$userid,$password]);
    $check($status === 200 && count($admins['administrators']) === 2, 'Project owner can add a second administrator through the API');
    $check(!isset($admins['administrators'][0]['email']) && !isset($admins['administrators'][0]['password_hash']), 'Project administrator API must omit private account information');
    [$status] = $request('api/projects.php?app=' . $ownedApp, 'PUT', ['project_name' => 'Updated by coadmin'], ['X-Console-Session: 1'], [$coadminUserid,$password]);
    $check($status === 200, 'Added administrator can change project settings');
    [$status] = $request('api/project-administrators.php?app=' . $app, 'GET', null, ['X-Console-Session: 1'], [$coadminUserid,$password]);
    $check($status === 403, 'Coadmin must not see administrators of an unassigned project');
    [$status] = $request('api/admin-users.php', 'GET', null, ['X-Console-Session: 1'], [$coadminUserid,$password]);
    $check($status === 403, 'Coadmin must not access global user administration');
    [$status] = $request('api/projects.php?app=' . $ownedApp, 'DELETE', null, ['X-Console-Session: 1'], [$coadminUserid,$password]);
    $check($status === 403, 'Coadmin must not delete the creator’s project');
    [$status] = $request('api/project-administrators.php?app=' . $ownedApp, 'DELETE', ['userid' => $userid], ['X-Console-Session: 1'], [$coadminUserid,$password]);
    $check($status === 409, 'Coadmin must not remove creator access');
    [$status] = $request('api/project-administrators.php?app=' . $ownedApp, 'DELETE', ['userid' => $coadminUserid], ['X-Console-Session: 1'], [$userid,$password]);
    $check($status === 200, 'Owner can remove coadministrator privileges');
    [$status] = $request('api/projects.php?app=' . $ownedApp, 'PUT', ['project_name' => 'Must not change'], ['X-Console-Session: 1'], [$coadminUserid,$password]);
    $check($status === 403, 'Removed administrator must immediately lose project management access');
    [$status] = $request('api/activities.php', 'POST', ['app' => $app, 'id' => 'flow/1', 'osmid' => 'node/1', 'body' => 'preserve posted data'], [], [$userid, $password]);
    $check($status === 201, 'Verified user can post to a project joined as contributor');
    [$status, $before] = $request('api/activities.php?app=' . $app . '&id=flow%2F1');
    $check($status === 200 && $before['updated_by_userid'] === $userid, 'Public API exposes editor user ID');
    $deletion = ['identity' => $email, 'password' => $password, 'confirm_userid' => $userid];
    [$status] = $request('auth/delete-account.php', 'GET');
    $check($status === 405, 'GET must never delete');
    [$status] = $request('auth/delete-account.php', 'POST', $deletion);
    $check($status === 403, 'Missing same-origin action header must be rejected');
    [$status] = $request('auth/delete-account.php', 'POST', array_replace($deletion, ['password' => 'wrong']), ['X-Account-Action: delete']);
    $check($status === 401, 'Wrong password must not delete');
    [$status, $deleted] = $request('auth/delete-account.php', 'POST', $deletion, ['X-Account-Action: delete']);
    $check($status === 200 && $deleted['account_deleted'], 'Self deletion must succeed');
    [$status, $after] = $request('api/activities.php?app=' . $app . '&id=flow%2F1');
    $check($status === 200 && $after['body'] === $before['body'] && $after['updated_by_userid'] === null, 'Public posted data remains with detached attribution');
    [$status] = $request('api/activities.php', 'POST', ['app' => $app, 'id' => 'flow/2', 'osmid' => 'node/1'], [], [$userid, $password]);
    $check($status === 401, 'Deleted credentials must not authenticate');
    [$status] = $request('auth/verify.php?token=' . $token);
    $check($status === 400, 'Deleted account verification tokens must not work');
    echo "Local HTTP flow: registration, email verification, own-project creation and management, access isolation, posting and deletion passed.\n";
} finally {
    // Only this run's uniquely named fixtures; existing data remains untouched.
    foreach (['activities', 'projects'] as $table) {
        $cleanup = $pdo->prepare("DELETE FROM $table WHERE app_key IN (?, ?, ?, ?)");
        $cleanup->execute([$app, $app . '-private', $app . '-disabled', $ownedApp]);
    }
    $cleanup = $pdo->prepare('DELETE FROM users WHERE userid IN (?, ?)');
    $cleanup->execute([$userid,$coadminUserid]);
}
