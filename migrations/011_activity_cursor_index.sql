-- Bounded keyset reads for project activity batches.
CREATE INDEX idx_activities_app_active_id ON activities (app_key, is_deleted, id);
