<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
Http::requireMethod('POST');
Http::run(function () use ($container): array {
    $container['auth']->requestPasswordReset(Http::jsonInput(), Http::clientIdentifier($container['auth_config']));
    return [202, [
        'status' => 'ok',
        'accepted' => true,
        'message' => '登録されているメールアドレスの場合、再設定メールを送信しました。',
    ]];
});

