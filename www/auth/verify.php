<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\RegistrationProjects;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/RegistrationProjects.php';
Http::requireMethod('GET');
// Older verification emails may link directly to this API. Browser navigation
// goes through the confirmation page without consuming the one-time token.
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document'
    || (stripos($accept, 'text/html') !== false && stripos($accept, 'application/json') === false)) {
    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    $appKey = is_string($_GET['app_key'] ?? null) ? $_GET['app_key'] : '';
    header('Location: ../verify-email.html?token=' . rawurlencode($token) . ($appKey === '' ? '' : '&app_key=' . rawurlencode($appKey)), true, 303);
    exit;
}
Http::run(function () use ($container): array {
    $id = $container['auth']->verifyEmail((string)($_GET['token'] ?? ''));
    $projects = [];
    foreach ($container['project_access']->visibleProjects(['id' => $id, 'role' => 'user'], $container['project_repo']->list(true)) as $project) {
        $item = RegistrationProjects::present($project);
        if ($item !== null) $projects[] = $item;
    }
    return [200, ['status' => 'ok', 'email_verified' => true, 'projects' => $projects]];
});
