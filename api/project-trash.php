<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\ConsoleSession;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$container = require dirname(__DIR__) . '/bootstrap.php';
$user = ConsoleSession::pageUser($container);
if ($user === null) {
    http_response_code(401);
    exit('Authentication required.');
}
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Administrator access required.');
}
header('Cache-Control: no-store');
header('Location: ../admin/?view=trash', true, 303);
exit;
