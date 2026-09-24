-- Apply once to existing databases before deploying the updated backend.
ALTER TABLE activities
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER osmid,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;
