<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxTemplateImporter.php';

final class SectionElementPresetEnhancementTest extends TestCase
{
    public function testRefreshedPresetsImportStandardElementsAndShippedAssets(): void
    {
        $markers = [
            'card-grid' => 'yk-card-media-framed',
            'faq-accordion' => 'data-blox-faq-style="divided"',
            'faq-split' => 'data-blox-faq-style="soft"',
            'testimonial-quote' => 'yk-quote-center',
            'testimonial-grid' => 'yk-quote-soft',
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

    public function testTestimonialHeadingPrecedesResponsiveEditableQuotes(): void
    {
        $raw = (string) file_get_contents(ROOT_PATH . '/templates/blox/sections/testimonial-grid.json');
        $prepared = BloxTemplateImporter::prepare($raw);
        $section = $prepared['sections'][0];
        self::assertCount(1, $section['columns']);
        [$heading, $container] = $section['columns'][0]['elements'];
        self::assertSame('heading', $heading['type']);
        self::assertSame('container', $container['type']);
        self::assertSame(['d' => 'row', 'm' => 'column'], $container['data']['direction']);
        self::assertCount(3, $container['data']['children']);
        foreach ($container['data']['children'] as $quote) {
            self::assertSame('quote', $quote['type']);
            self::assertSame('soft', $quote['data']['quote_style']);
        }
    }
}
