CREATE TABLE activities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_key VARCHAR(64) NOT NULL,
    activity_key VARCHAR(128) NOT NULL,
    form_key VARCHAR(64) NULL,
    osmid VARCHAR(64) NOT NULL,
    data_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_activities_app_activity (app_key, activity_key),
    KEY idx_activities_app_osmid (app_key, osmid),
    KEY idx_activities_app_updated (app_key, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
