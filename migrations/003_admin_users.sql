ALTER TABLE users
    ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'user' AFTER status,
    ADD COLUMN last_login_at DATETIME NULL AFTER email_verified_at,
    ADD KEY idx_users_role_status (role, status);

ALTER TABLE activities
    ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER data_json,
    ADD COLUMN updated_by_user_id BIGINT UNSIGNED NULL AFTER created_by_user_id,
    ADD KEY idx_activities_created_by (created_by_user_id, created_at),
    ADD KEY idx_activities_updated_by (updated_by_user_id, updated_at),
    ADD CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_activities_updated_by FOREIGN KEY (updated_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE user_projects (
    user_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'editor',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, project_id),
    KEY idx_user_projects_project (project_id, role),
    CONSTRAINT fk_user_projects_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_projects_project FOREIGN KEY (project_id)
        REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(32) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    detail_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_admin_audit_admin_created (admin_user_id, created_at),
    KEY idx_admin_audit_target (target_type, target_id),
    CONSTRAINT fk_admin_audit_admin FOREIGN KEY (admin_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
