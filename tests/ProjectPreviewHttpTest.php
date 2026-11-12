<?php
declare(strict_types=1);
// Local seeded accounts only. Unique fixtures are removed in finally.
$pdo = new PDO(getenv('CMM_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=community_mapmaker;charset=utf8mb4', 'cmm', 'cmm_local_test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$base = 'http://127.0.0.1:18080/api/project-preview.php?app=';
$request = static function(string $app, ?string $user = null) use ($base): array {
    $headers = "Accept: application/json\r\nX-Console-Session: 1\r\n";
    if ($user !== null) $headers .= 'Authorization: Basic '.base64_encode($user.':LocalTestPass!')."\r\n";
    $body = file_get_contents($base.rawurlencode($app), false, stream_context_create(['http'=>['header'=>$headers,'ignore_errors'=>true,'timeout'=>15]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int)$match[1], json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
};
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$userId = (int)$pdo->query("SELECT id FROM users WHERE userid='localviewer'")->fetchColumn();
$check($userId>0,'Local viewer fixture required');
$app='preview-private-'.bin2hex(random_bytes(5)); $id=null;
try {
    // Use the same URL as a public project: private access must be checked before a shared preview cache hit.
    $url = $pdo->query("SELECT frontend_url FROM projects WHERE app_key='playgrounds'")->fetchColumn();
    [$code,$expected]=$request('playgrounds'); $check($code===200,'Public previews remain anonymous');
    $stmt=$pdo->prepare('INSERT INTO projects (app_key, project_name, frontend_url, frontend_public, schema_json, enabled, created_at, updated_at) VALUES (?, ?, ?, 0, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $stmt->execute([$app,'Private preview test',$url,'{"fields":{}}']); $id=(int)$pdo->lastInsertId();
    $check($request($app)[0]===401,'Private previews require authentication even when the URL is cached');
    $check($request($app,'localunassigned')[0]===404,'Unassigned users cannot see private metadata');
    $assign=$pdo->prepare('INSERT INTO user_projects (user_id, project_id, role, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE role=VALUES(role)');
    foreach(['viewer','contributor','editor','project_admin'] as $role) {
        $assign->execute([$userId,$id,$role]);
        [$code,$preview]=$request($app,'localviewer');
        $check($code===200 && $preview===$expected,'Member can read the same preview: '.$role);
    }
    $assign->execute([$userId,$id,'banned']);
    $check($request($app,'localviewer')[0]===404,'Banned members cannot read cached private previews');
    $check($request($app,'localadmin')[0]===200,'System administrators retain preview access');
    echo "Private project previews: anonymous/public, all member roles, unassigned, banned and admin access passed.\n";
} finally {
    if ($id!==null) {
        $pdo->prepare('DELETE FROM user_projects WHERE project_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
    }
}
