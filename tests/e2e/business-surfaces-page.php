<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$mode = (string) ($_GET['mode'] ?? 'normal');
$view = (string) ($_GET['view'] ?? 'front');
if (!in_array($mode, ['normal', 'custom', 'parent', 'hidden', 'manual'], true)
    || !in_array($view, ['front', 'legacy', 'preview', 'editor'], true)) {
    http_response_code(400);
    exit;
}
$fixtures = json_decode((string) file_get_contents($root . '/tests/smoke/fixtures.json'), true);
$blocks = array_map(static fn (string $type): array => ['type' => $type, 'enabled' => true], [
    'about', 'stats', 'channel:' . (int) $fixtures['product_page'], 'advantage', 'cta',
]);
if ($mode === 'custom') {
    $blocks[0]['bg_color'] = '#eef5ec';
    $blocks[2]['bg_image'] = '/themes/default/assets/images/cta/cta-smart-manufacturing.png';
}
if ($mode === 'manual') {
    $blocks[0]['home_surface'] = 'dark';
    $blocks[0]['bg_color'] = '#ff00ff'; // dormant custom setting must survive a forced tone
    $blocks[2]['home_surface'] = 'light';
}
$sections = [];
foreach ($blocks as $i => $block) {
    $sections[] = [
        'id' => 'surface-section-' . $i,
        'settings' => ['padding' => 'none', 'max_width' => 'full', 'container_gutter' => 'none'],
        'columns' => [['span' => 12, 'elements' => [[
            'id' => 'surface-element-' . $i, 'type' => 'home-block',
            'data' => array_merge($block, ['block_type' => $block['type']]),
        ]]]],
    ];
}
if ($mode === 'parent') {
    $sections[0]['settings']['bg_color'] = '#ecf3fa';
    $sections[1]['settings']['container_bg'] = '#313a29';
    $sections[2]['columns'][0]['card_bg'] = '#eeeeee';
}
if ($mode === 'hidden') {
    $sections[1]['settings']['hide_on'] = ['m'];
}
$document = json_encode(['sections' => $sections], JSON_THROW_ON_ERROR);
$GLOBALS['yikai_config_runtime_overrides'] = [
    'current_theme' => 'business', 'home_layout_active' => '0',
    'home_blox_active' => $view === 'legacy' ? '0' : '1',
    'home_blox_draft' => $document, 'home_blox_published' => $document,
    'home_blocks_config' => json_encode($blocks, JSON_THROW_ON_ERROR),
    'html_cache_enabled' => '0',
];
if ($view === 'editor') {
    $_GET['home'] = '1';
    require $root . '/admin/blox_editor.php';
    exit;
}
if ($view === 'preview') {
    define('ROOT_PATH', $root);
    require $root . '/includes/init.php';
    require_once $root . '/admin/includes/auth.php';
    checkLogin();
    requirePermission('blox_home');
    require $root . '/includes/builder/BloxCanvasPreview.php';
    $_POST['blox'] = '1';
    $_POST['blocks_data'] = $_POST['blocks_data'] ?? json_encode($sections, JSON_THROW_ON_ERROR);
    outputBloxCanvasPreview(true, 0);
    exit;
}
$_SERVER['REQUEST_URI'] = '/';
require $root . '/index.php';
