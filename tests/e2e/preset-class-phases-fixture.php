<?php
/**
 * 样式预设转全局类的四阶段对比（2.0.5 §5.3a）：造一个尚未转换的预设（升级前的样子）和一张引用它的 Blox 页面。
 *   seed    → 预设（无 class_id）+ 页面（文本 / 容器 / 按钮各挂预设）；输出 {page, url}
 *   doc     → 已发布文档里这三个元素的 data（看 _global_style / _classes）与预设的 class_id
 *   restore → 删页面、删期间新建的类、还原设计系统设置
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/hooks.php';
require ROOT_PATH . '/includes/HtmlCache.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';

$path = ROOT_PATH . '/storage/preset-class-phases.json';
$action = (string) ($argv[1] ?? '');
$state = is_file($path) ? json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : null;

if ($action === 'restore') {
    if (is_array($state)) {
        db()->delete('contents', 'channel_id = ?', [(int) $state['page']]);
        db()->delete('blox_page_drafts', 'page_id = ?', [(int) $state['page']]);
        if (db()->tableExists('content_revisions')) {
            db()->delete('content_revisions', 'target_type = ? AND target_id = ?', ['page', (int) $state['page']]);
        }
        db()->delete('channels', 'id = ?', [(int) $state['page']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_class_refs WHERE class_id IN (SELECT class_id FROM ' . DB_PREFIX . 'blox_global_classes WHERE created_at >= ?)', [(int) $state['started']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_global_classes WHERE created_at >= ?', [(int) $state['started']]);
        settingModel()->saveBatch([BloxDesignSystem::SETTING_KEY => (string) $state['design']]);
        unlink($path);
    }
    BloxGlobalClasses::invalidateStylesheet();
    cacheClear();
    HtmlCache::invalidate();
    echo "restored\n";
    exit;
}

if ($action === 'doc') {
    $row = db()->fetchOne('SELECT blocks_data FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [(int) $state['page']]);
    $stored = BloxDocumentPipeline::decode((string) ($row['blocks_data'] ?? ''));
    $out = ['class_id' => ''];
    foreach (BloxDesignSystem::snapshot()['styles'] as $style) {
        if ($style['id'] === 'brand_card') $out['class_id'] = (string) ($style['class_id'] ?? '');
    }
    foreach ((array) ($stored['sections'][0]['columns'][0]['elements'] ?? []) as $element) {
        $out[(string) $element['id']] = array_intersect_key((array) $element['data'], array_flip(['_global_style', '_global_style_snapshot', '_classes']));
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit;
}

if ($action !== 'seed' || $state !== null) {
    throw new RuntimeException('usage: seed (after restore) | doc | restore');
}
$started = time();
$design = (string) config(BloxDesignSystem::SETTING_KEY, '');
$current = json_decode($design, true);
$current = is_array($current) ? $current : ['schema' => 1, 'revision' => 1, 'tokens' => [], 'styles' => []];
// 升级前的预设：带快照、没有 class_id
$preset = ['id' => 'brand_card', 'name' => 'Brand card', 'category' => 'card', 'color' => '#0b6e8a', 'background' => '#f5efe6',
    'border_color' => '#c2410c', 'radius' => 'lg', 'status' => 'active', 'locked' => false, 'version' => 1];
$current['styles'] = array_values(array_filter((array) ($current['styles'] ?? []), static fn ($s): bool => ($s['id'] ?? '') !== 'brand_card'));
$current['styles'][] = $preset;
$current['revision'] = (int) ($current['revision'] ?? 1) + 1;
settingModel()->saveBatch([BloxDesignSystem::SETTING_KEY => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

$page = (int) channelModel()->create([
    'name' => 'Preset phases', 'slug' => 'preset-phases', 'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'content' => '', 'created_at' => $started, 'updated_at' => $started,
]);
file_put_contents($path, json_encode(['page' => $page, 'started' => $started, 'design' => $design], JSON_THROW_ON_ERROR));
$snapshot = array_intersect_key($preset, array_flip(['color', 'background', 'border_color', 'radius']));
$el = static fn (string $id, string $type, array $data): array => ['id' => $id, 'type' => $type,
    'data' => $data + ['_html_id' => $id, '_global_style' => 'brand_card', '_global_style_snapshot' => $snapshot]];
$json = json_encode(['schema' => 1, 'sections' => [[
    'settings' => ['padding' => 'md'],
    'columns' => [['elements' => [
        $el('pp-text', 'text', ['html' => '<p>Preset text</p>']),
        $el('pp-box', 'container', ['padding' => 'md', 'children' => [['id' => 'pp-box-text', 'type' => 'text', 'data' => ['html' => '<p>Inside</p>']]]]),
        $el('pp-btn', 'button', ['text' => 'Preset button', 'url' => '/contact.html']),
    ]]],
]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
BloxFeaturePolicy::asTrustedWrite(static function () use ($page, $json): void {
    PageBloxDocument::saveAndPublish($page, $json);
});
cacheClear();
HtmlCache::invalidate();
echo json_encode(['page' => $page, 'url' => channelUrl(channelModel()->find($page) ?? [])], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
