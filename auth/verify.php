<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Http;

$container = require dirname(__DIR__) . '/bootstrap.php';
Http::requireMethod('GET');
Http::run(function () use ($container): array {
    $container['auth']->verifyEmail((string)($_GET['token'] ?? ''));
    return [200, ['status' => 'ok', 'email_verified' => true]];
});

