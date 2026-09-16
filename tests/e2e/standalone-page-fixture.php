<?php
/**
 * 「独立页面」整页模板夹具：隐藏页头/页尾/标题/面包屑/侧栏 + 页内锚点。
 *
 * 由来 2026-09-16：随包整页模板缩减后，restaurant-landing 移出本地目录，
 * blox-standalone-page.spec.js 失去了唯一带 page_*_hidden 的夹具。被验证的是
 * **导入→历史→保存→发布**这条链路对页面外框设置的处理，与目录里恰好有哪几款模板无关，
 * 所以改由本夹具在库里种一份本地模板，而不是让随包目录为测试保留一款模板。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$name = 'E2E Standalone Page';
$action = (string) ($argv[1] ?? 'seed');

foreach (bloxTemplateModel()->catalog('page') as $row) {
    if ((string) ($row['name'] ?? '') === $name) {
        db()->delete('blox_templates', 'id = ?', [(int) $row['id']]);
    }
}
if ($action === 'cleanup') {
    echo "clean\n";
    exit(0);
}
if ($action !== 'seed') {
    fwrite(STDERR, "usage: php tests/e2e/standalone-page-fixture.php seed|cleanup\n");
    exit(2);
}

$section = static fn (string $anchor, array $elements): array => [
    'type' => 'section',
    'settings' => ['anchor_id' => $anchor, 'padding' => 'lg', 'max_width' => 'default'],
    'columns' => [['elements' => $elements]],
];
$heading = static fn (string $text, string $level = 'h2'): array => [
    'type' => 'heading',
    'data' => ['text' => $text, 'level' => $level],
];

$package = json_encode([
    'format' => BloxTemplateImporter::FORMAT,
    'version' => BloxTemplateImporter::VERSION,
    'type' => 'page',
    'name' => $name,
    'requires' => ['elements' => ['heading', 'button'], 'plugins' => []],
    'document' => [
        'schema' => 1,
        'settings' => [
            'page_header_hidden' => true,
            'page_footer_hidden' => true,
            'page_title_hidden' => true,
            'page_breadcrumb_hidden' => true,
            'page_sidebar_hidden' => true,
        ],
        'sections' => [
            $section('standalone-header', [
                $heading('独立页面夹具', 'h1'),
                ['type' => 'button', 'data' => ['text' => '去预约', 'url' => '#standalone-reservation']],
            ]),
            $section('standalone-body', [$heading('正文')]),
            $section('standalone-reservation', [$heading('预约')]),
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$result = BloxTemplateImporter::importJson((string) $package, 1, 'import', '');
bloxTemplateModel()->publishDraft((int) $result['id']);
echo json_encode(['id' => (int) $result['id'], 'sections' => (int) $result['sections']], JSON_THROW_ON_ERROR) . "\n";
