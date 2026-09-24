-- Apply once after the coordinate columns are available.
CREATE INDEX idx_activities_app_active_lon_lat
    ON activities (app_key, is_deleted, longitude, latitude);
