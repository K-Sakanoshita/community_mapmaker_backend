CREATE TABLE IF NOT EXISTS external_apps (
    client_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    app_name VARCHAR(255) NOT NULL,
    allowed_origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    redirect_uri VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app_key VARCHAR(64) NOT NULL,
    scopes JSON NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_external_apps_origin (allowed_origin, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_app_grants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_external_grants_app_user (client_id, user_id),
    CONSTRAINT fk_external_grants_app FOREIGN KEY (client_id) REFERENCES external_apps(client_id) ON DELETE CASCADE,
    CONSTRAINT fk_external_grants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_app_keys (
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    grant_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('code', 'token') NOT NULL,
    challenge CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_external_keys_grant_expiry (grant_id, expires_at),
    CONSTRAINT fk_external_keys_grant FOREIGN KEY (grant_id) REFERENCES external_app_grants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO external_apps (client_id, app_name, allowed_origin, redirect_uri, app_key, scopes)
VALUES
    ('machi-scrapbook', 'まちのスクラップブック', 'https://machi-scrapbook.openacrossbase.net', 'https://machi-scrapbook.openacrossbase.net/oauth/callback/', 'machi-scrapbook', '["batch:create","batch:update","batch:delete"]'),
    ('playgrounds', '遊具のある公園マップ', 'https://playgrounds.openacrossbase.net', 'https://playgrounds.openacrossbase.net/oauth/callback/', 'playgrounds', '["activities:create","activities:update","activities:delete"]')
ON DUPLICATE KEY UPDATE client_id = external_apps.client_id;
