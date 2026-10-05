ALTER TABLE projects
    ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER deleted_at,
    ADD KEY idx_projects_created_by (created_by_user_id),
    ADD CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL;
