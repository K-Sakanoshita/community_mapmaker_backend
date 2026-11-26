<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\{ExternalAppException, Http, ValidationException};

define('CMM_EXTERNAL_APP_CORS', true);
$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
Http::requireMethods(['POST']);

Http::run(function () use ($container): array {
    if (strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0])) !== 'application/x-www-form-urlencoded') {
        throw new ExternalAppException('invalid_request', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) throw new ExternalAppException('invalid_request', 400);
    parse_str($raw, $input);
    try {
        $token = $container['database']->run(fn() => $container['external_apps']->exchange($input, (string)($_SERVER['HTTP_ORIGIN'] ?? '')));
    } catch (ValidationException) {
        throw new ExternalAppException('invalid_request', 400);
    }
    return [200, $token];
});
