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
if (!in_array($mode, ['normal', 'custom', 'parent', 'hidden', 'manual', 'published-header'], true)
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
    // 编辑器走 HomeBloxDocument::load() 读 DATA_KEY，前台走 loadPublished() 读 PUBLISHED_KEY，
    // 两个都要覆盖。原先只写了 home_blox_published 和一个并不存在的 home_blox_draft 键，
    // 于是 view=editor 显示的是站点真实首页文档，用例里的区块序号全靠巧合成立。
    // 字面量而非类常量：此处 builder bootstrap 尚未载入。
    'home_blox_data' => $document,      // HomeBloxDocument::DATA_KEY
    'home_blox_published' => $document, // HomeBloxDocument::PUBLISHED_KEY
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
    // published-header 模式需要「存在一个已发布的 Blox 页头」来证明主题接管渲染时画布仍用主题页头。
    // 这个发布落在共享的隔离站点数据库里，之前从不还原：后续 site-design / blox-default-areas 等
    // 依赖「无已发布 Blox 页头」的用例在同一站点里全部失败（CI 单 worker 顺序执行必现，单跑各 spec 不现）。
    // 这里记录发布前状态，渲染结束后原样还原。outputBloxCanvasPreview() 内部会 exit，
    // PHP 在 exit 时不执行 finally，所以还原挂在 shutdown 函数上。
    if ($mode === 'published-header') {
        require_once $root . '/includes/builder/bootstrap.php';
        $before = bloxTemplateModel()->findWhere(['source' => 'builtin', 'source_ref' => 'clean-site-header']);
        $headerTemplate = BloxAreaTemplatePresets::install('clean-site-header', 1);
        $headerId = (int) $headerTemplate['id'];
        bloxTemplateModel()->publishDraft($headerId);
        register_shutdown_function(static function () use ($before, $headerId): void {
            if (!$before) {
                bloxTemplateModel()->deleteById($headerId);
            } elseif ((int) ($before['status'] ?? 0) !== 1) {
                // 原样还原：status / published_at / published_data 三个发布态字段都回到发布前的值
                bloxTemplateModel()->updateById($headerId, [
                    'status' => 0,
                    'published_at' => (int) ($before['published_at'] ?? 0),
                    'published_data' => $before['published_data'] ?? null,
                ]);
            }
        });
    }
    require $root . '/includes/builder/BloxCanvasPreview.php';
    $_POST['blox'] = '1';
    $_POST['blocks_data'] = $_POST['blocks_data'] ?? json_encode($sections, JSON_THROW_ON_ERROR);
    outputBloxCanvasPreview(true, 0);
    exit;
}
$_SERVER['REQUEST_URI'] = '/';
require $root . '/index.php';
