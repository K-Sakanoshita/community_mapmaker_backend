CREATE TABLE IF NOT EXISTS osm_identities (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    osm_subject VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY uq_osm_identities_subject (osm_subject),
    CONSTRAINT fk_osm_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
