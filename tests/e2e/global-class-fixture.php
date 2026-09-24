<?php
/**
 * 全局类直接编辑（V2.0.0 验收用例 1/2）夹具：一个 Blox 页面 Section > Container > 两个 Heading，
 * 都不带本地样式；类由用例在编辑器里创建。restore 删除页面与本夹具期间新建的全局类。
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

$path = ROOT_PATH . '/storage/global-class-fixture.json';
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
$page = (int) channelModel()->create([
    'name' => 'Global class fixture', 'slug' => 'global-class-fixture',
    'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'content' => '', 'created_at' => $started, 'updated_at' => $started,
]);
file_put_contents($path, json_encode(['page' => $page, 'started' => $started], JSON_THROW_ON_ERROR));

$heading = static fn (string $id, string $text): array => [
    'id' => $id, 'type' => 'heading', 'data' => ['text' => $text, 'level' => 'h2'],
];
$json = json_encode([
    'schema' => 1,
    'sections' => [[
        'settings' => ['padding' => 'lg'],
        'columns' => [['elements' => [[
            'id' => 'gcf-container',
            'type' => 'container',
            'data' => ['children' => [
                $heading('gcf-heading-a', 'Class heading A'),
                $heading('gcf-heading-b', 'Class heading B'),
            ]],
        ]]]],
    ]],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
BloxFeaturePolicy::asTrustedWrite(static function () use ($page, $json): void {
    PageBloxDocument::saveAndPublish($page, $json);
});
cacheClear();
HtmlCache::invalidate();
$channel = channelModel()->find($page) ?? [];
echo json_encode(['page' => $page, 'url' => channelUrl($channel)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
