<?php
declare(strict_types=1);

// Local Docker test configuration only. Do not reuse these credentials in production.
$webPort = (string)(getenv('CMM_TEST_WEB_PORT') ?: '18080');
$httpsPort = (string)(getenv('CMM_TEST_HTTPS_PORT') ?: '18443');
$lanHost = trim((string)(getenv('CMM_TEST_LAN_HOST') ?: ''));
$lanHttpsOrigin = filter_var($lanHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    ? 'https://' . $lanHost . ':' . $httpsPort
    : null;
$lanHttpOrigin = filter_var($lanHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    ? 'http://' . $lanHost . ':' . $webPort
    : null;
$tailscaleHost = trim((string)(getenv('CMM_TEST_TAILSCALE_HOST') ?: ''));
$tailscaleHttpOrigin = filter_var($tailscaleHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    ? 'http://' . $tailscaleHost . ':' . $webPort
    : null;
$hostName = trim((string)(getenv('CMM_TEST_HOSTNAME') ?: ''));
$tailscaleDns = rtrim(trim((string)(getenv('CMM_TEST_TAILSCALE_DNS') ?: '')), '.');
$publicBaseUrl = $lanHttpsOrigin ?? ('http://127.0.0.1:' . $webPort);

$allowedOrigins = [
    'http://127.0.0.1:' . $webPort,
    'http://localhost:' . $webPort,
];
foreach ([$lanHttpOrigin, $lanHttpsOrigin, $tailscaleHttpOrigin] as $origin) {
    if ($origin !== null) $allowedOrigins[] = $origin;
}
foreach ([$hostName, $tailscaleDns] as $name) {
    if ($name !== '' && preg_match('/\A[a-zA-Z0-9.-]+\z/', $name) === 1) {
        $allowedOrigins[] = 'http://' . $name . ':' . $webPort;
    }
}

/*
 * Local test CORS policy:
 * - exact origins above remain supported;
 * - localhost, loopback, RFC1918 LAN addresses and Tailscale/CGNAT IPv4
 *   addresses may be used as browser origins on any TCP port;
 * - the detected local hostname and Tailscale DNS name may also use any port.
 *
 * Production configuration does not enable this option.
 */
$allowedOriginHosts = array_values(array_filter(array_unique([
    'localhost',
    $hostName,
    $tailscaleDns,
])));

return [
    'db' => [
        'dsn' => 'mysql:host=database;dbname=community_mapmaker;charset=utf8mb4',
        'user' => 'cmm',
        'password' => 'cmm_local_test',
    ],
    'auth' => [
        'registration_enabled' => true,
        'email_verification' => false,
        'verification_token_ttl_seconds' => 86400,
        'password_reset_token_ttl_seconds' => 3600,
        'resend_cooldown_seconds' => 60,
        'password_min_length' => 8,
        'allow_insecure_local_urls' => true,
        'verification_url' => $publicBaseUrl . '/auth/verify.php',
        'password_reset_url' => $publicBaseUrl . '/reset-password.html',
        'rate_limit_secret' => 'local-test-only-rate-limit-secret-change-in-production',
        'allowed_origins' => array_values(array_unique($allowedOrigins)),
        'allow_private_network_origins' => true,
        'allowed_origin_hosts' => $allowedOriginHosts,
        'trust_proxy_headers' => false,
        'rate_limits' => [
            'register_client' => ['limit' => 100, 'window_seconds' => 3600],
            'register_email' => ['limit' => 100, 'window_seconds' => 3600],
            'register_userid' => ['limit' => 100, 'window_seconds' => 3600],
            'resend_client' => ['limit' => 100, 'window_seconds' => 3600],
            'resend_email' => ['limit' => 100, 'window_seconds' => 3600],
            'reset_client' => ['limit' => 100, 'window_seconds' => 3600],
            'reset_email' => ['limit' => 100, 'window_seconds' => 3600],
        ],
    ],
    'activity' => [
        'max_payload_bytes' => 262144,
        'apps' => [
            'playgrounds' => [
                'project_name' => '公園Activity（ローカル）',
                'id_prefix' => 'Playgrounds',
                'schema_file' => '/app/config/activity-schemas/playgrounds.json',
                'write_auth_required' => true,
            ],
        ],
    ],
    'mail' => [
        'from_address' => 'noreply@example.test',
        'from_name' => 'Community Map Maker Local',
        'site_name' => 'Community Map Maker Local',
    ],
];
