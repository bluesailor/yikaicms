<?php
declare(strict_types=1);
// Reuse the core navigation, language handling and builder hooks.
$extraCss = ($extraCss ?? '') . '<link rel="stylesheet" href="' . e(theme_asset('studio.css')) . '">';
require ROOT_PATH . '/themes/default/layouts/header.php';
