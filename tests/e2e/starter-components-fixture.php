<?php
/**
 * 预置组件（v2.1.0）e2e 夹具：补装 8 个预置组件，建一个每种各放一个实例的 Blox 页面。
 * seed → 输出 {page,url}；restore 删除夹具期间新建的一切。
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

$path = ROOT_PATH . '/storage/starter-components-fixture.json';
$action = (string) ($argv[1] ?? '');
$state = is_file($path) ? json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : null;

if ($action === 'restore') {
    if (is_array($state)) {
        db()->delete('contents', 'channel_id = ?', [(int) $state['page']]);
        db()->delete('blox_page_drafts', 'page_id = ?', [(int) $state['page']]);
        db()->delete('channels', 'id = ?', [(int) $state['page']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . "blox_templates WHERE type = 'component' AND source = 'builtin' AND created_at >= ?", [(int) $state['started']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_revisions WHERE created_at >= ?', [(int) $state['started']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_refs WHERE doc_key = ?', ['page:' . (int) $state['page']]);
        unlink($path);
    }
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
BloxComponentPresets::installMissing();
$page = (int) channelModel()->create([
    'name' => 'Starter components', 'slug' => 'starter-components-fixture',
    'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'content' => '', 'created_at' => $started, 'updated_at' => $started,
]);
file_put_contents($path, json_encode(['page' => $page, 'started' => $started], JSON_THROW_ON_ERROR));

$instances = [];
foreach (BloxComponentPresets::PRESETS as $slug) {
    $instances[] = ['id' => 'sc-' . $slug, 'type' => 'component', 'data' => ['component' => BloxComponentPresets::uuid($slug), 'props' => []]];
}
$json = json_encode([
    'schema' => 1,
    'sections' => [[
        'id' => 'sc-s', 'settings' => ['padding' => 'lg', 'max_width' => 'wide'],
        'columns' => [['id' => 'sc-c', 'elements' => [[
            'id' => 'sc-grid', 'type' => 'container',
            'data' => ['layout' => 'grid', 'grid_cols' => ['d' => '3', 't' => '2', 'm' => '1'], 'gap' => ['d' => 'lg'], 'children' => $instances],
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
