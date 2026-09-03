<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
Http::requireMethod('POST');
Http::run(function () use ($container): array {
    $result = $container['auth']->register(Http::jsonInput(), Http::clientIdentifier($container['auth_config']));
    return [201, ['status' => 'ok'] + $result];
});

