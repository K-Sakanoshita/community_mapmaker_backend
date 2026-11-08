<?php
declare(strict_types=1);

// Support both the legacy repository document root and the public www/ layout.
$runtime = dirname(__DIR__) . '/_runtime.php';
if (is_file($runtime)) require_once $runtime;
$privateRoot = defined('CMM_PRIVATE_ROOT') ? CMM_PRIVATE_ROOT : dirname(__DIR__);

use CommunityMapMaker\Activity\ActivityApi;
use CommunityMapMaker\Activity\ActivityValidationException;
use CommunityMapMaker\Activity\ThumbnailBusyException;
use CommunityMapMaker\Activity\ThumbnailSourceException;
use CommunityMapMaker\Activity\ThumbnailSource;
use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\RateLimiter;

$container = require $privateRoot . '/bootstrap.php';
require_once $privateRoot . '/lib/ActivityApi.php';
$method = Http::requireMethods(['GET', 'POST']);

ActivityApi::run(function () use ($container, $method): array {
    $input = $method === 'POST' ? Http::jsonInput(8192) : $_GET;
    foreach (['app', $method === 'POST' ? 'url' : 'id'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
            throw new ActivityValidationException([$key => 'A non-empty string is required.']);
        }
    }
    $app = trim($input['app']);
    if ($method === 'POST') {
        // Arbitrary URL generation is restricted even for projects allowing anonymous posts.
        ActivityApi::requireProjectWriteAccess($container, $app);
        $url = trim($input['url']);
    } else {
        $field = $input['field'] ?? 'image';
        $index = $input['index'] ?? '0';
        if (!is_string($field) || !is_string($index) || !preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/', $index)) {
            throw new ActivityValidationException(['field' => 'Expected a field name and a non-negative image index (0-999).']);
        }
        if (($container['activity_schema']->get($app)['fields'][$field]['type'] ?? '') !== 'image') {
            throw new ActivityValidationException(['field' => 'Expected an image-type field.']);
        }
        $row = $container['activity']->find($app, trim($input['id']));
        $value = $row[$field] ?? null;
        $url = is_array($value) ? ($value[(int)$index] ?? null) : ($index === '0' ? $value : null);
        if ($url === null || $url === '') return [404, ['status' => 'error', 'code' => 'image_not_found']];
    }
    try {
        if (!is_string($url)) throw new ThumbnailSourceException('Expected an image URL.');
        $url = ThumbnailSource::normalizeUrl($url);
    } catch (ThumbnailSourceException) {
        throw new ActivityValidationException(['url' => 'Expected an absolute HTTP(S) image URL without credentials or fragment.']);
    }
    try {
        $result = $container['thumbnail']->get($url, static function () use ($container): void {
            $limiter = new RateLimiter($container['database']->pdo(), (string)$container['auth_config']['rate_limit_secret']);
            if (!$limiter->hit('thumbnail_client', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 30, 60)) {
                header('Retry-After: 60');
                Http::respond(429, ['status' => 'error', 'code' => 'rate_limit_exceeded']);
            }
        });
    } catch (ThumbnailBusyException) {
        header('Retry-After: 2');
        return [503, ['status' => 'error', 'code' => 'thumbnail_busy']];
    } catch (ThumbnailSourceException) {
        return [502, ['status' => 'error', 'code' => 'image_unavailable']];
    }
    $etag = '"' . hash('sha256', $result['body']) . '"';
    // Revalidate Activity visibility on every request, including conditional requests.
    header('Content-Type: ' . $result['mime']);
    header('Cache-Control: private, no-cache');
    header('ETag: ' . $etag);
    if ($method === 'GET' && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . strlen($result['body']));
    echo $result['body'];
    exit;
});
