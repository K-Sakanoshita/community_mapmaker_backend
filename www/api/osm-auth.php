<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\{AuthDisabledException, ConsoleSession, DuplicateIdentityException, ExternalAppException, Http,
    OsmIdentityAlreadyLinkedException, OsmIdentityRepository, OsmOAuth, RateLimitExceededException, ValidationException};

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
$registrationEnabled = ($container['auth_config']['registration_enabled'] ?? false) === true;

try {
    $user = ConsoleSession::user($container);
    if ($method === 'GET') {
        Http::respond(200, [
            'enabled' => true,
            'signed_in' => $user !== null,
            'linked' => $user !== null && $identities->isLinked((int)$user['id']),
            'registration_enabled' => $registrationEnabled,
        ]);
    }

    $input = Http::jsonInput(8192);
    $action = $input['action'] ?? '';
    if (in_array($action, ['login', 'link', 'register', 'external'], true)) {
        $external = null;
        if ($action === 'link') {
            if ($user === null) Http::respond(401, ['code' => 'authentication_required']);
            if ($identities->isLinked((int)$user['id'])) Http::respond(409, ['code' => 'already_linked']);
        } elseif ($action === 'external') {
            $external = $container['external_apps']->requestAuthorization($input);
            if (($user['console_auth_method'] ?? null) === 'osm') {
                ConsoleSession::rememberExternalAuthorization($external);
                Http::respond(200, ['consent' => true]);
            }
        } elseif ($user !== null) {
            Http::respond(409, ['code' => 'already_signed_in']);
        }

        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $view = $input['view'] ?? '';
        $app = $input['app'] ?? '';
        $registration = $action === 'register' ? $container['auth']->validateOsmRegistration($input) : null;
        ConsoleSession::beginOsmAuthorization([
            'state' => $state,
            'verifier' => $verifier,
            'expires_at' => time() + 600,
            'user_id' => $user === null ? null : (int)$user['id'],
            'lang' => ($input['lang'] ?? '') === 'en' ? 'en' : 'ja',
            'view' => in_array($view, ['mypage', 'detail', 'columns', 'activities', 'create', 'users', 'all-users', 'trash'], true) ? $view : '',
            'app' => $registration['app_key'] ?? (is_string($app) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $app) ? $app : ''),
            'registration' => $registration,
            'external' => $external,
        ]);
        Http::respond(200, ['authorization_url' => $osm->authorizationUrl($state, $verifier)]);
    }

    if ($action !== 'complete') Http::respond(400, ['code' => 'invalid_osm_request']);
    $state = $input['state'] ?? null;
    $flow = is_string($state) ? ConsoleSession::consumeOsmAuthorization($state) : null;
    if ($flow === null) Http::respond(400, ['code' => 'invalid_osm_state']);

    $result = [
        'lang' => $flow['lang'],
        'link' => $flow['user_id'] !== null && !isset($flow['external']),
        'external' => isset($flow['external']),
        'registration' => isset($flow['registration']),
        'can_register' => $registrationEnabled,
        'view' => $flow['view'],
        'app' => $flow['app'],
    ];
    $code = $input['code'] ?? null;
    if (!is_string($code) || $code === '' || strlen($code) > 2048) {
        Http::respond(400, $result + ['code' => 'osm_authorization_cancelled']);
    }
    $profile = $osm->profile($code, $flow['verifier']);
    $subject = $profile['subject'];
    // Re-read the session in case it changed while OpenStreetMap was contacted.
    $user = ConsoleSession::user($container);
    if (($user === null ? null : (int)$user['id']) !== $flow['user_id']) {
        Http::respond(409, $result + ['code' => 'osm_session_changed']);
    }

    if ($result['link']) {
        if (!$identities->link((int)$user['id'], $subject, $profile['display_name'])) {
            Http::respond(409, $result + ['code' => 'osm_link_conflict']);
        }
    } else {
        $id = $identities->userId($subject);
        if (isset($flow['external']) && $flow['user_id'] !== null && $id !== $flow['user_id']) {
            Http::respond(403, $result + ['code' => 'osm_account_unavailable']);
        }
        $created = null;
        if ($id === null && isset($flow['registration'])) {
            $created = $container['auth']->registerOsm(
                $flow['registration'], Http::clientIdentifier($container['auth_config']), $profile, $identities
            );
            $id = (int)$created['id'];
        }
        if ($id === null) Http::respond(403, $result + ['code' => 'osm_account_unlinked']);
        $user = $container['user_repo']->findById($id);
        if ($user === null || $user['status'] !== 'active' || !in_array($user['role'], ['user', 'admin'], true)) {
            Http::respond(403, $result + ['code' => 'osm_account_unavailable']);
        }
        if ($created === null && $profile['display_name'] !== null) {
            $identities->updateDisplayName($id, $subject, $profile['display_name']);
        }
        $container['user_repo']->recordLogin($id);
        ConsoleSession::establish($user, 'osm', $flow['external'] ?? null);
    }
    Http::respond(200, $result + ['status' => 'ok']);
} catch (ExternalAppException $error) {
    Http::respond($error->status, ['code' => $error->getMessage()]);
} catch (AuthDisabledException) {
    Http::respond(403, ($result ?? []) + ['code' => 'registration_disabled']);
} catch (DuplicateIdentityException) {
    Http::respond(409, ($result ?? []) + ['code' => 'identity_already_registered']);
} catch (OsmIdentityAlreadyLinkedException) {
    Http::respond(409, ($result ?? []) + ['code' => 'osm_link_conflict']);
} catch (RateLimitExceededException) {
    header('Retry-After: 60');
    Http::respond(429, ($result ?? []) + ['code' => 'rate_limit_exceeded']);
} catch (ValidationException $error) {
    Http::respond(422, ($result ?? []) + ['code' => 'validation_failed', 'errors' => $error->errors]);
} catch (Throwable $error) {
    $requestId = bin2hex(random_bytes(8));
    error_log(sprintf('OSM authentication error [%s]: %s', $requestId, $error::class));
    Http::respond(500, ($result ?? []) + ['code' => 'osm_authentication_failed', 'request_id' => $requestId]);
}
