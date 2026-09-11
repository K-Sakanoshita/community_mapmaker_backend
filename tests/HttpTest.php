<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Http;

require_once dirname(__DIR__) . '/lib/Http.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$originalServer = $_SERVER;

try {
    unset(
        $_SERVER['PHP_AUTH_USER'],
        $_SERVER['PHP_AUTH_PW'],
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    );

    $_SERVER['PHP_AUTH_USER'] = 'native-user';
    $_SERVER['PHP_AUTH_PW'] = 'native-password';
    assertSameValue(
        ['native-user', 'native-password'],
        Http::basicCredentials(),
        'Native PHP Basic credentials must be used'
    );

    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('header-user:password:with:colons');
    assertSameValue(
        ['header-user', 'password:with:colons'],
        Http::basicCredentials(),
        'Authorization header must be decoded and split only once'
    );

    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'basic ' . base64_encode('redirect-user:redirect-password');
    assertSameValue(
        ['redirect-user', 'redirect-password'],
        Http::basicCredentials(),
        'Redirected Authorization header must be supported'
    );

    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer token';
    assertSameValue(null, Http::basicCredentials(), 'Non-Basic authorization must be rejected');

    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('missing-separator');
    assertSameValue(null, Http::basicCredentials(), 'Credentials without a colon must be rejected');
} finally {
    $_SERVER = $originalServer;
}

echo "HTTP Basic credential parsing: ok\n";
