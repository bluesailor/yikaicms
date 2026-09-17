<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxTemplateImporter.php';

/**
 * 随包 section 预置确实用上了元素的样式能力（不是光有 JSON、渲染出来是裸样式）。
 *
 * 2026-09-16 随包目录缩减为六款后，card-grid / faq-accordion / faq-split /
 * testimonial-grid 已移出，相应取样随之移除。被移除的是「这些预置声明了该样式」这层
 * 目录断言；**元素样式本身**的覆盖在各自的专属测试里，未受影响：
 * quote_style → BasicContentVariantsTest，FAQ 样式 → AccordionStyleTest，
 * 卡片图框 → CardImageFramingTest。
 */
final class SectionElementPresetEnhancementTest extends TestCase
{
    public function testShippedPresetsImportStandardElementsAndShippedAssets(): void
    {
        $markers = [
            // 06 客户评价：标题 + 3 条逐条切换的评价轮播
            'testimonial-quote' => 'yk-testimonials--single',
        ];
        foreach ($markers as $slug => $marker) {
            $raw = (string) file_get_contents(ROOT_PATH . '/templates/blox/sections/' . $slug . '.json');
            $prepared = BloxTemplateImporter::prepare($raw);
            self::assertCount(1, $prepared['sections']);
            $document = ['schema' => 1, 'settings' => [], 'sections' => $prepared['sections']];
            $html = BlockRenderer::render(json_encode($document, JSON_THROW_ON_ERROR));
            self::assertStringContainsString($marker, $html, $slug);
            self::assertStringNotContainsString('/uploads/', $raw);
            preg_match_all('#"(/(?:assets/images|images)/[^"\\\\]+)"#', $raw, $assets);
            foreach (array_unique($assets[1]) as $asset) {
                self::assertFileExists(ROOT_PATH . $asset);
            }
        }
    }

    /**
     * 容器的响应式方向与嵌套子元素必须完整穿过导入管线（手机端堆叠靠的就是这个）。
     * 原以随包的 testimonial-grid 取样；该预置移出后改用内联夹具，保住管线覆盖。
     */
    public function testResponsiveContainerDirectionAndNestedChildrenSurviveImport(): void
    {
        $package = (string) json_encode([
            'format' => 'yikaicms-blox-template',
            'version' => 1,
            'type' => 'section',
            'name' => 'Responsive container fixture',
            'requires' => ['elements' => ['heading', 'container', 'quote'], 'plugins' => []],
            'document' => ['schema' => 1, 'settings' => [], 'sections' => [[
                'type' => 'section',
                'settings' => [],
                'columns' => [['elements' => [
                    ['type' => 'heading', 'data' => ['text' => '客户如何评价', 'level' => 'h2']],
                    ['type' => 'container', 'data' => [
                        'direction' => ['d' => 'row', 'm' => 'column'],
                        'children' => array_fill(0, 3, [
                            'type' => 'quote',
                            'data' => ['text' => '交付很稳。', 'quote_style' => 'soft'],
                        ]),
                    ]],
                ]]],
            ]]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $prepared = BloxTemplateImporter::prepare($package);
        $section = $prepared['sections'][0];
        self::assertCount(1, $section['columns']);
        [$heading, $container] = $section['columns'][0]['elements'];
        self::assertSame('heading', $heading['type']);
        self::assertSame('container', $container['type']);
        self::assertSame(['d' => 'row', 'm' => 'column'], $container['data']['direction'], '响应式方向不能在导入时被压平');
        self::assertCount(3, $container['data']['children']);
        foreach ($container['data']['children'] as $quote) {
            self::assertSame('quote', $quote['type']);
            self::assertSame('soft', $quote['data']['quote_style']);
        }
    }
}
