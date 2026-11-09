<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\ThumbnailBusyException;
use CommunityMapMaker\Activity\ThumbnailService;

require_once dirname(__DIR__) . '/lib/ThumbnailService.php';

// Workers hold real flock locks in separate PHP processes until the parent releases them.
if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $dir, $url, $label] = $argv;
    $service = new ThumbnailService(['cache_dir' => $dir, 'max_entries' => 2], static function () use ($dir, $label): string {
        touch($dir . '/entered-' . $label);
        if ($label === 'failure') throw new RuntimeException('Expected download failure.');
        $deadline = microtime(true) + 15;
        while (!is_file($dir . '/release-' . $label)) {
            if (microtime(true) > $deadline) throw new RuntimeException('Worker release timed out.');
            usleep(10000);
        }
        return file_get_contents($dir . '/source.jpg');
    });
    try {
        $result = $service->get($url);
        echo json_encode(['status' => 'ok', 'cached' => $result['cached']]);
    } catch (ThumbnailBusyException) { echo json_encode(['status' => 'busy']); }
    catch (Throwable $error) { echo json_encode(['status' => 'error', 'message' => $error->getMessage()]); }
    exit;
}

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
$dir = sys_get_temp_dir() . '/cmm-thumb-concurrency-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$image = imagecreatetruecolor(800, 400);
imagejpeg($image, $dir . '/source.jpg'); imagedestroy($image);
$jobs = [];
$spawn = static function (string $name, string $image) use ($dir, &$jobs): void {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $dir, 'https://example.org/' . $image . '.jpg', $name],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/result-' . $name, 'w'], 2 => ['file', $dir . '/error-' . $name, 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start worker.');
    fclose($pipes[0]); $jobs[$name] = $process;
};
$waitForEntry = static function (string $name) use ($dir): void {
    $deadline = microtime(true) + 5;
    while (!is_file($dir . '/entered-' . $name)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker failed to enter generation: ' . $name);
        usleep(10000);
    }
};
$finish = static function (string $name) use ($dir, &$jobs): array {
    $deadline = microtime(true) + 5;
    while (proc_get_status($jobs[$name])['running']) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker did not finish: ' . $name);
        usleep(10000);
    }
    proc_close($jobs[$name]); unset($jobs[$name]);
    return json_decode(file_get_contents($dir . '/result-' . $name), true, 512, JSON_THROW_ON_ERROR);
};
try {
    $cached = new ThumbnailService(['cache_dir' => $dir, 'max_entries' => 2], static fn() => file_get_contents($dir . '/source.jpg'));
    $cached->get('https://example.org/cached.jpg');
    $spawn('a', 'a'); $waitForEntry('a');
    $spawn('duplicate', 'a');
    $check($finish('duplicate')['status'] === 'busy' && !is_file($dir . '/entered-duplicate'), 'Same image cannot consume the second slot');
    $spawn('b', 'b'); $waitForEntry('b');
    $check(!is_file($dir . '/release-a'), 'A second distinct image starts while the first is still blocked');
    $spawn('c', 'c');
    $check($finish('c')['status'] === 'busy' && !is_file($dir . '/entered-c'), 'A third distinct image is refused');
    $check($cached->get('https://example.org/cached.jpg')['cached'], 'Cached images remain available with both slots occupied');
    touch($dir . '/release-a');
    $check($finish('a') === ['status' => 'ok', 'cached' => false], 'First generation completes normally');
    $check($cached->get('https://example.org/a.jpg')['cached'], 'Completed image can be reused while another generation runs');
    $spawn('duplicate-b', 'b');
    $check($finish('duplicate-b')['status'] === 'busy' && !is_file($dir . '/entered-duplicate-b'), 'Same-image rejection also releases a previously found free slot');
    $spawn('failure', 'failure');
    $check($finish('failure')['status'] === 'error', 'Download exception propagates');
    $spawn('crashed', 'crashed'); $waitForEntry('crashed');
    $check(true, 'An exception releases its generation slot');
    proc_terminate($jobs['crashed'], 9);
    $deadline = microtime(true) + 5;
    while (proc_get_status($jobs['crashed'])['running']) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker termination timed out.');
        usleep(10000);
    }
    proc_close($jobs['crashed']); unset($jobs['crashed']);
    $spawn('replacement', 'crashed'); $waitForEntry('replacement');
    $check(true, 'Process termination releases its slot, including the same image key');
    touch($dir . '/release-replacement'); touch($dir . '/release-b');
    $check($finish('replacement')['status'] === 'ok' && $finish('b')['status'] === 'ok', 'Two active generations publish successfully');
    $check(count(glob($dir . '/*.img')) <= 2, 'Concurrent publication preserves the cache capacity');
    $check(count(glob($dir . '/generation*.lock')) === 3, 'Lock files are bounded regardless of image count');
} finally {
    foreach ($jobs as $job) { proc_terminate($job, 9); proc_close($job); }
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
echo "Thumbnail concurrency: $checks checks passed.\n";
