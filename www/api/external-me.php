<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\Http;

define('CMM_EXTERNAL_APP_CORS', true);
$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
Http::requireMethods(['GET']);

Http::run(function () use ($container): array {
    $access = $container['database']->run(fn() => $container['external_apps']->access(Http::bearerToken(), (string)($_SERVER['HTTP_ORIGIN'] ?? '')));
    return [200, ['userid' => $access['userid'], 'app_key' => $access['app_key'], 'scopes' => $access['scopes']]];
});
