<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\ConsoleSession;
use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\OsmIdentityRepository;
use CommunityMapMaker\Auth\OsmOAuth;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/OsmOAuth.php';
require_once CMM_PRIVATE_ROOT . '/lib/OsmIdentityRepository.php';

$method = Http::requireMethods(['GET', 'POST']);
if (($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') {
    Http::respond(403, ['code' => 'console_header_required']);
}

$osm = new OsmOAuth((array)($container['auth_config']['osm_oauth'] ?? []));
if (!$osm->enabled()) Http::respond(200, ['enabled' => false]);
$identities = new OsmIdentityRepository($container['database']->pdo());

try {
    $user = ConsoleSession::user($container);
    if ($method === 'GET') {
        Http::respond(200, [
            'enabled' => true,
            'signed_in' => $user !== null,
            'linked' => $user !== null && $identities->isLinked((int)$user['id']),
        ]);
    }

    $input = Http::jsonInput(8192);
    $action = $input['action'] ?? '';
    if ($action === 'login' || $action === 'link') {
        if ($action === 'link') {
            if ($user === null) Http::respond(401, ['code' => 'authentication_required']);
            if ($identities->isLinked((int)$user['id'])) Http::respond(409, ['code' => 'already_linked']);
        } elseif ($user !== null) {
            Http::respond(409, ['code' => 'already_signed_in']);
        }

        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $view = $input['view'] ?? '';
        $app = $input['app'] ?? '';
        ConsoleSession::beginOsmAuthorization([
            'state' => $state,
            'verifier' => $verifier,
            'expires_at' => time() + 600,
            'user_id' => $user === null ? null : (int)$user['id'],
            'lang' => ($input['lang'] ?? '') === 'en' ? 'en' : 'ja',
            'view' => in_array($view, ['mypage', 'detail', 'columns', 'activities', 'create', 'users', 'all-users', 'trash'], true) ? $view : '',
            'app' => is_string($app) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $app) ? $app : '',
        ]);
        Http::respond(200, ['authorization_url' => $osm->authorizationUrl($state, $verifier)]);
    }

    if ($action !== 'complete') Http::respond(400, ['code' => 'invalid_osm_request']);
    $state = $input['state'] ?? null;
    $flow = is_string($state) ? ConsoleSession::consumeOsmAuthorization($state) : null;
    if ($flow === null) Http::respond(400, ['code' => 'invalid_osm_state']);

    $result = [
        'lang' => $flow['lang'],
        'link' => $flow['user_id'] !== null,
        'view' => $flow['view'],
        'app' => $flow['app'],
    ];
    $code = $input['code'] ?? null;
    if (!is_string($code) || $code === '' || strlen($code) > 2048) {
        Http::respond(400, $result + ['code' => 'osm_authorization_cancelled']);
    }
    $subject = $osm->subject($code, $flow['verifier']);
    // Re-read the session in case it changed while OpenStreetMap was contacted.
    $user = ConsoleSession::user($container);
    if (($user === null ? null : (int)$user['id']) !== $flow['user_id']) {
        Http::respond(409, $result + ['code' => 'osm_session_changed']);
    }

    if ($result['link']) {
        if (!$identities->link((int)$user['id'], $subject)) {
            Http::respond(409, $result + ['code' => 'osm_link_conflict']);
        }
    } else {
        $id = $identities->userId($subject);
        $user = $id === null ? null : $container['user_repo']->findById($id);
        if ($user === null || $user['status'] !== 'active' || !in_array($user['role'], ['user', 'admin'], true)) {
            Http::respond(403, $result + ['code' => 'osm_account_unavailable']);
        }
        $container['user_repo']->recordLogin($id);
        ConsoleSession::establish($user);
    }
    Http::respond(200, $result + ['status' => 'ok']);
} catch (Throwable $error) {
    $requestId = bin2hex(random_bytes(8));
    error_log(sprintf('OSM authentication error [%s]: %s', $requestId, $error::class));
    Http::respond(500, ($result ?? []) + ['code' => 'osm_authentication_failed', 'request_id' => $requestId]);
}
