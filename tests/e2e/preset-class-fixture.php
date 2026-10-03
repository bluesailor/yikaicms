<?php
/**
 * 样式预设收编为全局类（RFC-1 第 4 点，2.0.4）夹具：一个预设 + 一个 Blox 页面，
 * 标题 A 引用预设且带本地文字颜色（验证预设的优先级在转换后不变），标题 B 不引用。
 * restore 删除页面、本夹具期间新建的全局类，并还原设计系统设置。
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
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}

$path = ROOT_PATH . '/storage/preset-class-fixture.json';
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
if ($action !== 'seed') {
    throw new RuntimeException('usage: seed|restore');
}
if ($state !== null) {
    throw new RuntimeException('Restore the previous fixture first');
}

$started = time();
$design = (string) config(BloxDesignSystem::SETTING_KEY, '');
$decoded = json_decode($design, true);
$decoded = is_array($decoded) ? $decoded : [];
$decoded['styles'] = [[
    'id' => 's_e2e_card', 'name' => 'E2E Card', 'category' => 'component',
    'color' => '#9a3412', 'background' => '#ffedd5', 'border_color' => '#fb923c', 'radius' => 'lg',
    'status' => 'active', 'locked' => false, 'version' => 1,
]];
$decoded['revision'] = max(1, (int) ($decoded['revision'] ?? 1)) + 1;
settingModel()->saveBatch([BloxDesignSystem::SETTING_KEY => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

$page = (int) channelModel()->create([
    'name' => 'Preset class fixture', 'slug' => 'preset-class-fixture',
    'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'content' => '', 'created_at' => $started, 'updated_at' => $started,
]);
file_put_contents($path, json_encode(['page' => $page, 'started' => $started, 'design' => $design], JSON_THROW_ON_ERROR));

$json = json_encode([
    'schema' => 1,
    'sections' => [[
        'settings' => ['padding' => 'lg'],
        'columns' => [['elements' => [
            ['id' => 'pcf-heading-a', 'type' => 'heading', 'data' => [
                'text' => 'Preset heading A', 'level' => 'h2', 'color' => '#111111',
                '_global_style' => 's_e2e_card',
                '_global_style_snapshot' => ['color' => '#9a3412', 'background' => '#ffedd5', 'border_color' => '#fb923c', 'radius' => 'lg'],
            ]],
            ['id' => 'pcf-heading-b', 'type' => 'heading', 'data' => ['text' => 'Plain heading B', 'level' => 'h2']],
        ]]],
    ]],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
BloxFeaturePolicy::asTrustedWrite(static function () use ($page, $json): void {
    PageBloxDocument::saveAndPublish($page, $json);
});
cacheClear();
HtmlCache::invalidate();
$channel = channelModel()->find($page) ?? [];
echo json_encode(['page' => $page, 'url' => channelUrl($channel)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
