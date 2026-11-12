<?php
declare(strict_types=1);

// PHP's development server otherwise falls back to index.html for missing paths.
$root = realpath(__DIR__ . '/../../www');
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath($root . $path);
if ($file !== false && ($file === $root || str_starts_with($file, $root . '/'))
    && !preg_match('~(?:^|/)[._][^/]*(?:/|$)~', $path)
    && (is_file($file) || is_file($file . '/index.php') || is_file($file . '/index.html'))) {
    return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found\n";
