<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
Http::requireMethod('POST');
Http::run(function () use ($container): array {
    $container['auth']->resetPassword(Http::jsonInput());
    return [200, ['status' => 'ok', 'password_reset' => true]];
});

