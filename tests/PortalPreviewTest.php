<?php
declare(strict_types=1);

use CommunityMapMaker\Activity\PortalPreview;

require_once dirname(__DIR__) . '/lib/PortalPreview.php';

function previewAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$html = '<meta content="地域の公園 &amp; 遊具" property="og:description"><meta property="og:image" content="/image/ogimage.png">';
$preview = PortalPreview::fromHtml($html, 'https://example.jp/maps/');
previewAssert($preview['description'] === '地域の公園 & 遊具', 'OG description must decode HTML entities.');
previewAssert($preview['image_url'] === 'https://example.jp/image/ogimage.png', 'Root-relative OG image must resolve against frontend origin.');
previewAssert(PortalPreview::resolveUrl('https://example.jp/maps/', 'javascript:alert(1)') === null, 'Unsafe image URL must be rejected.');
previewAssert(PortalPreview::resolveUrl('https://example.jp/maps/', 'images/og.png') === 'https://example.jp/maps/images/og.png', 'Relative OG image must resolve against frontend path.');
echo "Portal preview behavior: ok\n";
