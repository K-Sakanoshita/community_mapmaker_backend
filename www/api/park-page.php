<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_runtime.php';

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\ParkNotFoundException;
use CommunityMapMaker\Activity\ParkSourceUnavailableException;
use CommunityMapMaker\Auth\Http;

$container = require CMM_PRIVATE_ROOT . '/bootstrap.php';
require_once CMM_PRIVATE_ROOT . '/lib/ActivityApi.php';
Http::requireMethod('GET');

ActivityApi::run(function () use ($container): array {
    foreach (['app', 'osmid'] as $key) {
        if (!isset($_GET[$key]) || !is_string($_GET[$key]) || trim($_GET[$key]) === '') {
            throw new ActivityValidationException([$key => 'A non-empty string is required.']);
        }
    }
    try {
        return [200, $container['park_page']->find(trim($_GET['app']), trim($_GET['osmid']))];
    } catch (ParkNotFoundException) {
        return [404, ['status' => 'error', 'code' => 'park_not_found']];
    } catch (ParkSourceUnavailableException) {
        header('Retry-After: 30');
        return [503, ['status' => 'error', 'code' => 'park_source_unavailable']];
    }
});
