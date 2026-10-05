<?php
declare(strict_types=1);

function checkEnglishAdmin(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
ob_start();
require $root . '/en/admin/index.php';
$html = (string)ob_get_clean();
checkEnglishAdmin(str_contains($html, '<html lang="en">'), 'English page language is missing.');
checkEnglishAdmin(str_contains($html, '<base href="../../admin/">'), 'Shared asset base is missing.');
checkEnglishAdmin(str_contains($html, 'i18n.js?v=20261003-activity-count'), 'Message loader is missing.');
checkEnglishAdmin(str_contains($html, 'admin.js?v=20261003-activity-count'), 'Shared script is missing.');
checkEnglishAdmin(str_contains($html, 'Project Management'), 'English page text is missing.');
checkEnglishAdmin(str_contains($html, 'id="columnsBackButton"') && str_contains($html, 'id="activitiesBackButton"') && str_contains($html, 'Back to projects'), 'Project list return buttons are missing.');
checkEnglishAdmin(!preg_match('/[ぁ-んァ-ン一-龥]/u', $html), 'Japanese interface text remains in English HTML.');

$script = file_get_contents($root . '/admin/admin.js');
$ja = json_decode(file_get_contents($root . '/admin/ja.json'), true, 512, JSON_THROW_ON_ERROR);
$en = json_decode(file_get_contents($root . '/admin/en.json'), true, 512, JSON_THROW_ON_ERROR);
preg_match_all('/\bt\("([a-z][a-z0-9_.]+)"/', $script, $matches);
checkEnglishAdmin(count($matches[1]) > 200, 'Shared script does not reference the message catalog.');
foreach (array_unique($matches[1]) as $key) {
    checkEnglishAdmin(isset($ja[$key], $en[$key]), "Missing translation: {$key}");
}
checkEnglishAdmin(array_keys($ja) === array_keys($en), 'Japanese and English catalogs differ.');
foreach ($en as $key => $value) {
    checkEnglishAdmin(!preg_match('/[ぁ-んァ-ン一-龥]/u', $value), "Japanese remains in English catalog: {$key}");
}
checkEnglishAdmin(!str_contains($script, 'strtr('), 'Script source must not be translated.');
checkEnglishAdmin(in_array('Sign in is required. Please sign in again.', $en, true), 'English API error is missing.');

echo "English administration UI: ok\n";
