<?php
/**
 * list-dynamic 退役回归的隔离数据（2026-09-20 冻结后 palette 无新增入口，
 * e2e 只能由 fixture 预置存量文档——这正是本组用例要守的现实）。
 *
 * seed：建一个 TB-LD 页面，Blox 文档带完整 list-dynamic（循环模板子元素 +
 *       loop_fallback + 数字分页 + 空态文案）并发布；输出 {page, url}。
 * cleanup：删页面与文档痕迹。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('An active disposable smoke site is required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/hooks.php'; // PageBloxDocument::saveAndPublish 触发 do_action
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Refusing a non-local fixture');
}

$statePath = ROOT_PATH . '/storage/e2e-list-dynamic-legacy.json';
$action = $argv[1] ?? '';

if ($action === 'cleanup') {
    if (is_file($statePath)) {
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        $pageId = (int) ($state['page'] ?? 0);
        if ($pageId > 0) {
            db()->delete('contents', 'channel_id = ?', [$pageId]);
            db()->delete('blox_page_drafts', 'page_id = ?', [$pageId]);
            db()->delete('channels', 'id = ?', [$pageId]);
        }
        db()->delete('contents', 'slug LIKE ?', ['tb-ld-%']);
        db()->delete('channels', 'slug LIKE ?', ['tb-ld-%']);
        unlink($statePath);
    }
    require_once ROOT_PATH . '/includes/HtmlCache.php';
    HtmlCache::invalidate();
    exit(0);
}
if ($action !== 'seed' || is_file($statePath)) {
    throw new RuntimeException('Usage: seed | cleanup (seed must start clean)');
}

// 数据源：一个 list 栏目 + 三条文章（limit 2 → 触发第二页，验证数字分页）
$newsId = (int) channelModel()->create([
    'name' => 'TB-LD News', 'slug' => 'tb-ld-news',
    'type' => 'list', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'created_at' => time(), 'updated_at' => time(),
]);
foreach (['TB-LD Legacy Alpha', 'TB-LD Legacy Beta', 'TB-LD Legacy Gamma'] as $index => $title) {
    contentModel()->create([
        'channel_id' => $newsId, 'title' => $title, 'slug' => 'tb-ld-item-' . $index,
        'summary' => 'LDONLY legacy summary ' . $index, 'type' => 'article', 'status' => 1,
        'lang' => 'zh-CN', 'publish_time' => time() - $index,
        'created_at' => time(), 'updated_at' => time(),
    ]);
}

// 存量形态的 list-dynamic 文档：children 循环模板（编辑器写出的现形态，
// DynamicLoopTemplateRenderer::bindNode 做 loop_field 绑定）+ 数字分页
$pageId = (int) channelModel()->create([
    'name' => 'TB-LD Legacy Page', 'slug' => 'tb-ld-legacy-page',
    'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'created_at' => time(), 'updated_at' => time(),
]);
$document = json_encode(['sections' => [[
    'settings' => [],
    'columns' => [['elements' => [[
        'type' => 'list-dynamic',
        'data' => [
            'query_source' => 'type:article',
            'keyword' => 'LDONLY', // 只圈自己的三条（连本页正文行都不进），分页恰为 2 页
            'limit' => 2,
            'pagination_mode' => 'numbers',
            'empty' => 'TB-LD empty',
            'children' => [[
                'type' => 'heading',
                'data' => ['text' => '', 'level' => 'h3', 'loop_field' => 'title', 'loop_fallback' => 'TB-LD Untitled'],
            ]],
        ],
    ]]]],
]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
// 循环模板子元素属 query_loop 专业能力；fixture 是代码内置内容，走可信写通道
BloxFeaturePolicy::asTrustedWrite(static fn (): array => PageBloxDocument::saveAndPublish($pageId, $document));
require_once ROOT_PATH . '/includes/HtmlCache.php';
HtmlCache::invalidate();

file_put_contents($statePath, json_encode(['page' => $pageId, 'news' => $newsId], JSON_THROW_ON_ERROR));
echo json_encode(['page' => $pageId, 'url' => '/tb-ld-legacy-page.html'], JSON_THROW_ON_ERROR);
