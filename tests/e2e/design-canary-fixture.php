<?php
/**
 * 设计系统金丝雀页（v2.1 三源归一的安全网）：固定一组设计数据——色板 token、全站主题（排版 / 按钮 / 布局）、
 * 一个全局类——和一张覆盖它们消费方的 Blox 页面。design-canary.spec.js 采集计算样式与 :root 变量，
 * 与基线逐项比对：设计数据换存储、换输出方式，前台必须语义等价。
 * restore 删除页面与期间新建的类，并还原三项设计设置。
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

const CANARY_SETTINGS = [BloxDesignSystem::SETTING_KEY, BloxDesignTheme::PUBLISHED_KEY, 'primary_color', 'secondary_color'];
$path = ROOT_PATH . '/storage/design-canary-fixture.json';
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
        settingModel()->saveBatch(array_map('strval', $state['settings']));
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
$previous = [];
foreach (CANARY_SETTINGS as $key) {
    $previous[$key] = (string) config($key, '');
}

// 设计数据：值都选非默认，才能看出它们确实生效
settingModel()->saveBatch([
    'primary_color' => '#0B6E8A',
    'secondary_color' => '#C2410C',
    BloxDesignSystem::SETTING_KEY => json_encode([
        'schema' => 1, 'revision' => 3,
        'tokens' => [
            ['id' => 'c_ink', 'name' => 'Ink', 'category' => 'neutral', 'value' => '#1f2937', 'status' => 'active', 'locked' => false, 'version' => 1],
            ['id' => 'c_sand', 'name' => 'Sand', 'category' => 'neutral', 'value' => '#f5efe6', 'status' => 'active', 'locked' => false, 'version' => 1],
        ],
        'styles' => [],
    ], JSON_THROW_ON_ERROR),
    BloxDesignTheme::PUBLISHED_KEY => json_encode(['revision' => 2, 'state' => [
        'typography' => [
            'h1' => ['family' => 'system-serif', 'size' => ['d' => 52, 't' => 44, 'm' => 34], 'weight' => '700', 'line_height' => 1.15, 'color' => 'c_ink'],
            'h2' => ['size' => ['d' => 36, 'm' => 28], 'weight' => '600', 'line_height' => 1.25],
            'h3' => ['size' => ['d' => 24], 'weight' => '600'],
            'body' => ['size' => ['d' => 17, 'm' => 16], 'line_height' => 1.75, 'color' => 'c_ink'],
        ],
        'buttons' => [
            'size' => ['d' => 15], 'padding_x' => 28, 'padding_y' => 13, 'radius' => 'full',
            'variants' => [
                'filled' => ['bg' => 'primary', 'color' => 'c_sand', 'hover' => ['bg' => 'secondary']],
                'outline' => ['border_color' => 'secondary', 'color' => 'secondary'],
            ],
        ],
        'layout' => ['content_max_width' => 1120, 'section_spacing' => ['d' => 96, 'm' => 56], 'container_gap' => ['d' => 40, 'm' => 20]],
    ]], JSON_THROW_ON_ERROR),
]);
BloxDesignTheme::resetCache();

$class = BloxGlobalClasses::mutate('class_add', [
    'name' => 'canary-card',
    'settings' => ['bg_color' => 'var(--yk-color-c_sand)', 'radius_px' => 14, 'padding_px' => ['d' => 32, 'm' => 20], 'border_color' => '#e5e7eb'],
], true);

$page = (int) channelModel()->create([
    'name' => 'Design canary', 'slug' => 'design-canary',
    'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'content' => '', 'created_at' => $started, 'updated_at' => $started,
]);
file_put_contents($path, json_encode(['page' => $page, 'started' => $started, 'settings' => $previous], JSON_THROW_ON_ERROR));

$el = static fn (string $id, string $type, array $data): array => ['id' => $id, 'type' => $type, 'data' => $data + ['_html_id' => $id]];
$json = json_encode([
    'schema' => 1,
    'sections' => [[
        'settings' => ['padding' => 'md'],
        'columns' => [['elements' => [
            $el('cn-h1', 'heading', ['text' => 'Canary heading one', 'level' => 'h1']),
            $el('cn-h2', 'heading', ['text' => 'Canary heading two', 'level' => 'h2']),
            $el('cn-h3', 'heading', ['text' => 'Canary heading three', 'level' => 'h3']),
            $el('cn-body', 'text', ['html' => '<p>Canary body copy for the design system.</p>']),
            $el('cn-btn-filled', 'button', ['text' => 'Filled', 'url' => '/contact.html', 'variant' => 'primary']),
            $el('cn-btn-outline', 'button', ['text' => 'Outline', 'url' => '/contact.html', 'variant' => 'outline']),
            $el('cn-btn-pill', 'button', ['text' => 'Pill', 'url' => '/contact.html', 'variant' => 'primary', 'shape' => 'pill']),
            $el('cn-box', 'container', ['_classes' => [$class['class_id']], 'children' => [
                ['id' => 'cn-box-text', 'type' => 'text', 'data' => ['html' => '<p>Inside a class</p>']],
                ['id' => 'cn-box-btn', 'type' => 'button', 'data' => ['text' => 'Inner', 'url' => '/about.html']],
            ]]),
            $el('cn-token-text', 'heading', ['text' => 'Token colour', 'level' => 'h3', 'color' => 'var(--yk-color-secondary)']),
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
