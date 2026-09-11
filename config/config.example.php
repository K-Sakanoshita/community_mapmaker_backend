<?php
declare(strict_types=1);

// Copy this file to config.php. Keep real credentials outside Git.
return [
    'db' => [
        'dsn' => getenv('CMM_DB_DSN') ?: 'mysql:host=localhost;dbname=community_mapmaker;charset=utf8mb4',
        'user' => getenv('CMM_DB_USER') ?: '',
        'password' => getenv('CMM_DB_PASSWORD') ?: '',
    ],
    'auth' => [
        'registration_enabled' => true,
        'email_verification' => true,
        'verification_token_ttl_seconds' => 86400,
        'password_reset_token_ttl_seconds' => 3600,
        'resend_cooldown_seconds' => 60,
        'password_min_length' => 8,
        'allow_insecure_local_urls' => false,
        'verification_url' => 'https://api.example.jp/auth/verify.php',
        'password_reset_url' => 'https://api.example.jp/reset-password.html',
        'rate_limit_secret' => getenv('CMM_RATE_LIMIT_SECRET') ?: '',
        'allowed_origins' => [
            'https://example.jp',
        ],
        'trust_proxy_headers' => false,
        'rate_limits' => [
            'register_client' => ['limit' => 5, 'window_seconds' => 3600],
            'register_email' => ['limit' => 5, 'window_seconds' => 3600],
            'register_userid' => ['limit' => 5, 'window_seconds' => 3600],
            'resend_client' => ['limit' => 10, 'window_seconds' => 3600],
            'resend_email' => ['limit' => 5, 'window_seconds' => 3600],
            'reset_client' => ['limit' => 10, 'window_seconds' => 3600],
            'reset_email' => ['limit' => 5, 'window_seconds' => 3600],
        ],
    ],
    'activity' => [
        'max_payload_bytes' => 262144,
        'apps' => [
            'playgrounds' => [
                'id_prefix' => 'Playgrounds',
                'schema_file' => getenv('CMM_PLAYGROUNDS_ACTIVITY_SCHEMA') ?: __DIR__ . '/activity-schemas/playgrounds.json',
                // Until #1 adds anonymous posting, writes require an active account.
                'write_auth_required' => true,
                'search' => [
                    'score_field' => 'score',
                    'attributes_field' => 'good_points',
                    'body_field' => 'body',
                    'detail_url_field' => 'detail_url',
                    'date_fields' => ['actdate', 'updatetime'],
                    'photo_field_pattern' => '/^picture_url\\d+$/',
                    'score_code_pattern' => '/^act_score_(\\d+)$/',
                    'score_code_offset' => 1,
                    'recent_days' => 365,
                    'sparse_information_count' => 2,
                ],
            ],
        ],
    ],
    'mail' => [
        'from_address' => 'noreply@example.jp',
        'from_name' => 'Community Map Maker',
        'site_name' => 'Community Map Maker',
    ],
];
