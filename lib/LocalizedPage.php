<?php
declare(strict_types=1);
/** Thin directory aliases reuse the public source instead of duplicating page markup. */
function renderLocalizedPage(string $page, string $language): void
{
    $pages = ['index.html', 'mypage.html', 'register.html', 'privacy.html', 'account.html', 'reset-password.html', 'verify-email.html', 'project.html', 'admin/index.html'];
    if (!in_array($page, $pages, true) || !in_array($language, ['ja', 'en'], true)) {
        http_response_code(404); return;
    }
    $source = file_get_contents((defined('CMM_PUBLIC_ROOT') ? CMM_PUBLIC_ROOT : dirname(__DIR__) . '/www') . '/' . $page);
    $source = str_replace('<html lang="ja">', '<html lang="' . $language . '">', $source);
    $base = $page === 'admin/index.html' ? '../../admin/' : '../';
    $source = str_replace('<head>', '<head>' . "\n  <base href=\"$base\">", $source);
    header('Content-Type: text/html; charset=utf-8');
    echo $source;
}
