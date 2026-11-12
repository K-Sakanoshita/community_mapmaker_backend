<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/cmm-layout-' . bin2hex(random_bytes(6));
$public = $temp . "/published site's files";
mkdir($temp, 0700);
$config = $temp . "/private config's.php";
file_put_contents($config, '<?php return [];');
function deployCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function deployRun(string $destination, string $config): int {
    global $root;
    exec('CMM_SAKURA_CONFIG_FILE=' . escapeshellarg($config) . ' sh ' . escapeshellarg($root . '/scripts/deploy-sakura.sh') . ' ' . escapeshellarg($destination) . ' 2>&1', $output, $status);
    return $status;
}
try {
    mkdir($public);
    foreach (['lib', 'config'] as $legacy) {
        mkdir($public . '/' . $legacy);
        file_put_contents($public . '/' . $legacy . '/legacy.php', '<?php');
    }
    file_put_contents($public . '/bootstrap.php', '<?php');
    file_put_contents($public . '/keep.txt', 'keep');
    deployCheck(deployRun($public, $config) === 0, 'Deployment failed.');
    foreach (['bootstrap.php', 'lib', 'config', 'schema.sql', 'tests', 'scripts', '.git'] as $private) {
        deployCheck(!file_exists($public . '/' . $private), 'Private file copied: ' . $private);
    }
    deployCheck(file_get_contents($public . '/keep.txt') === 'keep', 'Unrelated file changed.');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/www', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) continue;
        $relative = substr($file->getPathname(), strlen($root . '/www/'));
        deployCheck(is_file($public . '/' . $relative), 'Missing public file: ' . $relative);
        if ($relative !== '_runtime.php') deployCheck(hash_file('sha256', $file->getPathname()) === hash_file('sha256', $public . '/' . $relative), 'Public copy differs: ' . $relative);
    }
    $code = 'require $argv[1]."/_runtime.php"; if (CMM_PRIVATE_ROOT !== realpath($argv[2]) || CMM_PUBLIC_ROOT !== $argv[1] || getenv("CMM_AUTH_CONFIG") !== $argv[3]) exit(2); require $argv[1]."/ja/index.php";';
    exec('php -r ' . escapeshellarg($code) . ' ' . escapeshellarg($public) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($config), $output, $status);
    deployCheck($status === 0 && str_contains(implode("\n", $output), '<html lang="ja">'), 'Deployed page cannot load private code/public template.');
    deployCheck(deployRun($root . '/www/../www/invalid', $config) !== 0, 'Destination within repository accepted.');
    deployCheck(deployRun('/', $config) !== 0, 'Root destination accepted.');
    deployCheck(deployRun(dirname(realpath($root)), $config) !== 0, 'Repository parent accepted.');
    $inside = $public . '/secret.php';
    file_put_contents($inside, '<?php return [];');
    deployCheck(deployRun($public, $inside) !== 0, 'Public config accepted.');
    unlink($inside);
    symlink($root . '/www', $temp . '/public-link');
    deployCheck(deployRun($temp . '/public-link/invalid', $config) !== 0, 'Symlink destination within repository accepted.');
    deployCheck(deployRun($public, $config) === 0, 'Repeated deployment failed.');
    echo "Deployment layout: public copy equality, private paths, localized page, legacy cleanup, quoted paths and boundary guards passed.\n";
} finally {
    exec('rm -rf ' . escapeshellarg($temp));
}
