<?php
declare(strict_types=1);

use CommunityMapMaker\Auth\Database;

require_once dirname(__DIR__) . '/lib/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}
$identity = mb_strtolower(trim((string)($argv[1] ?? '')), 'UTF-8');
if ($identity === '') {
    fwrite(STDERR, "Usage: php scripts/grant-admin-role.php USERID_OR_EMAIL\n");
    exit(2);
}
$configFile = getenv('CMM_AUTH_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Backend config is missing.\n");
    exit(2);
}
$config = require $configFile;
$database = new Database((array)($config['db'] ?? []));
$statement = $database->pdo()->prepare(
    "UPDATE users SET role = 'admin', updated_at = :updated_at "
    . 'WHERE userid_normalized = :userid OR email_normalized = :email'
);
$statement->execute([
    ':updated_at' => gmdate('Y-m-d H:i:s'),
    ':userid' => $identity,
    ':email' => $identity,
]);
if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "User was not found or is already an admin.\n");
    exit(1);
}
fwrite(STDOUT, "Admin role granted.\n");
