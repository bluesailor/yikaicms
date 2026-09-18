<?php
/**
 * 为缺预览图的精品区块生成「真实渲染」的预览页。
 *
 * 用 BlockRenderer 渲染该区块本身的结构（不是线框图、更不是营销海报），
 * 输出成独立 HTML，交给浏览器按缩略图规格截图。
 *
 * 用法：php tools/render_premium_previews.php [slug ...]
 * 不给 slug 时渲染 manifest 里所有 thumbnail 为 null 的款。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$stageRoot = 'D:/phpstudy_pro/g5-local/claude-section-library';
$sourceDir = $stageRoot . '/source/sections';
$assetDir = $stageRoot . '/source/assets';
$outDir = $stageRoot . '/premium/preview-html';

$slugs = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => strpos($a, '--') !== 0));
if ($slugs === []) {
    $slugs = ['hero-split', 'feature-cards-soft', 'testimonial-grid', 'faq-split'];
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

// 包内素材引用的是站点绝对路径；预览页用 file:// 打开，改写成暂存资产的绝对路径
$assetBase = 'file:///' . str_replace('\\', '/', $assetDir);
$cssPath = 'file:///' . str_replace('\\', '/', ROOT_PATH . '/assets/css/tailwind.css');

foreach ($slugs as $slug) {
    $file = $sourceDir . '/' . $slug . '.json';
    if (!is_file($file)) {
        fwrite(STDERR, "skip {$slug}: 暂存目录无此款\n");
        continue;
    }
    $prepared = BloxTemplateImporter::prepare((string) file_get_contents($file));
    $html = BlockRenderer::render((string) json_encode(
        ['schema' => 1, 'settings' => [], 'sections' => $prepared['sections']],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
    $html = str_replace('/assets/images/blox-templates/', $assetBase . '/', $html);

    $page = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=1200">'
        . '<link rel="stylesheet" href="' . $cssPath . '">'
        . '<style>html,body{margin:0;padding:0;background:#fff}'
        // 缩略图规格 1200×525：超出部分裁掉，不缩放变形
        // 缩略图固定 1200×525：内容垂直居中，短区块不至于顶在上方留一大片白
        . '#shot{width:1200px;height:525px;overflow:hidden;position:relative;display:flex;flex-direction:column;justify-content:center}'
        . '#shot > *{width:100%}</style>'
        . '</head><body><div id="shot">' . $html . '</div></body></html>';

    $target = $outDir . '/' . $slug . '.html';
    file_put_contents($target, $page);
    echo "rendered {$slug} -> {$target}\n";
}
