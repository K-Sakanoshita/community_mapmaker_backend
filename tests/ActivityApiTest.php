<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;

require_once dirname(__DIR__) . '/lib/Contracts.php';
require_once dirname(__DIR__) . '/lib/ActivitySchema.php';
require_once dirname(__DIR__) . '/lib/ActivityApi.php';

function apiAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$_GET = [];
unset($_SERVER['PATH_INFO']);
apiAssert(ActivityApi::optionalActivityKey() === null, 'A list request must not resolve an Activity ID.');

$_GET['id'] = 'Playgrounds/0001';
apiAssert(ActivityApi::optionalActivityKey() === 'Playgrounds/0001', 'A query-string Activity ID must be resolved.');

$_SERVER['PATH_INFO'] = '/Playgrounds%2F0002';
apiAssert(ActivityApi::optionalActivityKey() === 'Playgrounds/0002', 'A path Activity ID must be decoded and take precedence.');

$_GET = [];
unset($_SERVER['PATH_INFO']);
try {
    ActivityApi::activityKey([]);
    throw new RuntimeException('A write request without an Activity ID must fail.');
} catch (ActivityValidationException $error) {
    apiAssert(isset($error->errors['id']), 'A missing Activity ID must produce an id validation error.');
}

echo "Activity API routing: ok\n";
