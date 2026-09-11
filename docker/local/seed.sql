-- Local test account only: localadmin / LocalTestPass!
INSERT INTO users (
    userid,
    userid_normalized,
    email,
    email_normalized,
    password_hash,
    status,
    role,
    email_verified_at,
    created_at,
    updated_at
) VALUES (
    'localadmin',
    'localadmin',
    'localadmin@example.test',
    'localadmin@example.test',
    '$2y$12$owGGDYKRZIOpyYmcGWKB/ejJ2Pl9KQzFGYQPCVpB4HnH3XLWDthzC',
    'active',
    'admin',
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    email_normalized = VALUES(email_normalized),
    password_hash = VALUES(password_hash),
    status = 'active',
    role = 'admin',
    email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()),
    updated_at = UTC_TIMESTAMP();

-- Keep a DB-managed Project available for Project-access tests without overwriting local edits.
INSERT INTO projects (app_key, project_name, schema_json, enabled, created_at, updated_at)
VALUES (
    'playgrounds',
    'Playgrounds',
    '{"fields":{"actdate":{"label":"投稿日","type":"date","required":true,"admin":{"visible":true,"editable":true,"width":140}},"title":{"label":"タイトル","type":"text","maxLength":255,"admin":{"visible":true,"editable":true,"width":220}},"score":{"label":"評価","type":"select","options":["act_score_1","act_score_2","act_score_3","act_score_4","act_score_5"],"admin":{"visible":true,"editable":true,"width":130}},"good_points":{"label":"よい点","type":"checkbox","options":["act_good_points_1","act_good_points_2","act_good_points_3","act_good_points_4","act_good_points_5"],"admin":{"visible":true,"editable":true,"width":180}},"body":{"label":"本文","type":"textarea","maxLength":10000,"admin":{"visible":true,"editable":true,"width":320}},"detail_url":{"label":"詳細URL","type":"url","maxLength":2048,"admin":{"visible":true,"editable":true,"width":240}}}}',
    1,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE app_key = VALUES(app_key);

-- Local Project-access test accounts only; both use LocalTestPass!.
INSERT INTO users (
    userid, userid_normalized, email, email_normalized, password_hash,
    status, role, email_verified_at, created_at, updated_at
) VALUES
    ('localeditor', 'localeditor', NULL, NULL, '$2y$12$owGGDYKRZIOpyYmcGWKB/ejJ2Pl9KQzFGYQPCVpB4HnH3XLWDthzC', 'active', 'user', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('localviewer', 'localviewer', NULL, NULL, '$2y$12$owGGDYKRZIOpyYmcGWKB/ejJ2Pl9KQzFGYQPCVpB4HnH3XLWDthzC', 'active', 'user', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('localunassigned', 'localunassigned', NULL, NULL, '$2y$12$owGGDYKRZIOpyYmcGWKB/ejJ2Pl9KQzFGYQPCVpB4HnH3XLWDthzC', 'active', 'user', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    status = 'active',
    role = 'user',
    updated_at = UTC_TIMESTAMP();

INSERT INTO user_projects (user_id, project_id, role, created_at)
SELECT u.id, p.id, assignments.role, UTC_TIMESTAMP()
FROM (
    SELECT 'localeditor' AS userid, 'editor' AS role
    UNION ALL
    SELECT 'localviewer', 'viewer'
) assignments
JOIN users u ON u.userid_normalized = assignments.userid
JOIN projects p ON p.app_key = 'playgrounds'
ON DUPLICATE KEY UPDATE role = VALUES(role);
