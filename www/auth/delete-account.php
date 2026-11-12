<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\AccountDeletion;
use CommunityMapMaker\Auth\AccountCredentialsException;
use CommunityMapMaker\Auth\LastAdministratorException;
use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\RateLimiter;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/AccountDeletion.php';
Http::requireMethod('POST');
// JSON plus a same-origin-only custom header prevents cross-site form/cookie deletion.
// Authenticate freshly from the supplied password; never accept a target ID or cookie.
if (($_SERVER['HTTP_X_ACCOUNT_ACTION'] ?? '') !== 'delete'
    || strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
    Http::respond(403, ['status' => 'error', 'code' => 'account_header_required']);
}
Http::run(function () use ($container): array {
    $secret = (string)$container['auth_config']['rate_limit_secret'];
    $pdo = $container['database']->pdo();
    $service = new AccountDeletion($pdo, new RateLimiter($pdo, $secret), $secret);
    try {
        $service->delete(Http::jsonInput(), Http::clientIdentifier($container['auth_config']));
    } catch (AccountCredentialsException) {
        return [401, ['status' => 'error', 'code' => 'invalid_credentials']];
    } catch (LastAdministratorException) {
        return [409, ['status' => 'error', 'code' => 'last_administrator']];
    }
    return [200, ['status' => 'ok', 'account_deleted' => true]];
});
