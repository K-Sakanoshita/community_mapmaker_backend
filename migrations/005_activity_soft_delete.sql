-- Apply once to existing databases before deploying the updated backend.
ALTER TABLE activities
    ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN deleted_at DATETIME NULL;
