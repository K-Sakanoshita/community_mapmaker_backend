<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\AuthService;
use CommunityMapMaker\Auth\Database;
use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\NativeMailer;
use CommunityMapMaker\Auth\RateLimiter;
use CommunityMapMaker\Auth\TokenRepository;
use CommunityMapMaker\Auth\UserRepository;

require_once __DIR__ . '/lib/Contracts.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/UserRepository.php';
require_once __DIR__ . '/lib/TokenRepository.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/NativeMailer.php';
require_once __DIR__ . '/lib/AuthService.php';
require_once __DIR__ . '/lib/Http.php';

try {
    $configFile = getenv('CMM_AUTH_CONFIG') ?: __DIR__ . '/config/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Auth config is missing. Copy config/config.example.php to config/config.php.');
    }
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Auth config must return an array.');

    $authConfig = (array)($config['auth'] ?? []);
    Http::prepare($authConfig);
    $database = new Database((array)($config['db'] ?? []));
    $authService = new AuthService(
        new UserRepository($database->pdo()),
        new TokenRepository($database->pdo()),
        new RateLimiter($database->pdo(), (string)($authConfig['rate_limit_secret'] ?? '')),
        $database,
        new NativeMailer((array)($config['mail'] ?? [])),
        $authConfig
    );

    return ['auth' => $authService, 'auth_config' => $authConfig];
} catch (Throwable $error) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $requestId = bin2hex(random_bytes(8));
    error_log(sprintf('Auth bootstrap error [%s]: %s', $requestId, $error->getMessage()));
    Http::respond(500, ['status' => 'error', 'code' => 'server_error', 'request_id' => $requestId]);
}
