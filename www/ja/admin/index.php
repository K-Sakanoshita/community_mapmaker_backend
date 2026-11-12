<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_runtime.php';
require_once CMM_PRIVATE_ROOT . '/lib/LocalizedPage.php';
renderLocalizedPage('admin/index.html', 'ja');
