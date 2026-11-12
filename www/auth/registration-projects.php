<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\RegistrationProjects;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/RegistrationProjects.php';
Http::requireMethod('GET');
Http::run(function () use ($container): array {
    $projects = [];
    foreach ($container['project_repo']->list(true) as $project) {
        $item = RegistrationProjects::present($project);
        if ($item !== null) $projects[] = $item;
    }
    return [200, ['projects' => $projects]];
});
