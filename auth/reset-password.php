<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
Http::requireMethod('POST');
Http::run(function () use ($container): array {
    $container['auth']->resetPassword(Http::jsonInput());
    return [200, ['status' => 'ok', 'password_reset' => true]];
});

