ALTER TABLE projects
    ADD COLUMN frontend_url VARCHAR(2048) NULL AFTER project_name,
    ADD COLUMN frontend_public TINYINT(1) NOT NULL DEFAULT 0 AFTER frontend_url;
