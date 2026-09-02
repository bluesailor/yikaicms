<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$theme = (string) ($_GET['theme'] ?? 'business');
$mode = (string) ($_GET['mode'] ?? 'none');
if (!in_array($theme, ['business', 'aurora', 'minimal', 'trade'], true)
    || !in_array($mode, ['none', 'banner', 'later', 'mobile-hidden', 'blox-none', 'blox-empty'], true)) {
    http_response_code(400);
    exit;
}

$banner = ['type' => 'banner', 'enabled' => true];
$about = ['type' => 'about', 'enabled' => true];
$blocks = $mode === 'none' ? [$about] : ($mode === 'later' ? [$about, $banner] : [$banner, $about]);
$sections = [[
    'settings' => ['padding' => 'none', 'max_width' => 'full', 'container_gutter' => 'none'],
    'columns' => [['span' => 12, 'elements' => [[
        'type' => 'home-block',
        'data' => $mode === 'blox-empty'
            ? ['block_type' => 'banner', 'enabled' => true, 'items_mode' => 'custom', 'children' => [], 'empty_state' => 'hide']
            : ['block_type' => 'about', 'enabled' => true],
    ]]]],
]];
$GLOBALS['yikai_config_runtime_overrides'] = [
    'current_theme' => $theme,
    'home_layout_active' => '0',
    'home_blox_active' => str_starts_with($mode, 'blox-') ? '1' : '0',
    'home_blox_published' => json_encode(['sections' => $sections], JSON_THROW_ON_ERROR),
    'home_blocks_config' => json_encode($blocks, JSON_THROW_ON_ERROR),
    'html_cache_enabled' => '0',
];
if ($mode === 'mobile-hidden') {
    $GLOBALS['yikai_config_runtime_overrides']['custom_head_code'] =
        '<style>@media(max-width:767px){.banner-swiper{display:none!important}}</style>';
}
$_SERVER['REQUEST_URI'] = '/';
require $root . '/index.php';
