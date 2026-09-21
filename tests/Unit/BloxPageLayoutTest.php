<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxPageLayout.php';

final class BloxPageLayoutTest extends TestCase
{
    public function testMaintenanceModeCannotChangeOrClearPageOverrides(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $GLOBALS['_test_config']['blox_maintenance_mode'] = '1';
        $base = json_encode(['settings' => ['page_content_background' => null], 'sections' => []], JSON_THROW_ON_ERROR);
        try {
            self::assertSame(['page_content_background' => null], BloxDocumentPipeline::process($base, trustedJson: $base)['settings']);
            $this->expectException(RuntimeException::class);
            BloxDocumentPipeline::process('{"settings":{},"sections":[]}', trustedJson: $base);
        } finally {
            unset($GLOBALS['_test_config']['blox_maintenance_mode']);
        }
    }

    public function testAbsenceFalseZeroAndClearHaveDifferentMeanings(): void
    {
        $theme = ThemeSettings::defaults();
        $theme['general']['page_header_hidden'] = '1';
        $theme['general']['page_content_gutter'] = 24;
        $context = ['type' => 'page', 'id' => 1, 'lang' => 'zh-CN'];
        $inherit = BloxPageLayout::resolve([], $context, $theme);
        self::assertTrue($inherit['values']['page_header_hidden']);
        self::assertSame(24, $inherit['values']['page_content_gutter']);
        $local = BloxPageLayout::resolve(['page_header_hidden' => false, 'page_content_gutter' => 0, 'page_content_background' => null], $context, $theme);
        self::assertFalse($local['values']['page_header_hidden']);
        self::assertSame('set', $local['sources']['page_header_hidden']);
        self::assertSame(0, $local['values']['page_content_gutter']);
        self::assertSame('clear', $local['sources']['page_content_background']);
        self::assertStringContainsString('padding-inline:0px', BloxPageLayout::css($local));
        self::assertStringContainsString('background-color:transparent', BloxPageLayout::css($local));
        self::assertSame($inherit, BloxPageLayout::resolve([], $context, $theme));
    }

    public function testOnlyBackgroundCanBeClearedAndNoCssCanBeInjected(): void
    {
        foreach ([['page_content_gutter' => null], ['page_content_gutter' => false], ['page_content_gutter' => '0'], ['page_content_gutter' => 81], ['page_content_max_width' => 759], ['page_content_background' => 'red'], ['page_content_background' => '#FFFFFF;display:none']] as $settings) {
            try { BloxPageLayout::normalize($settings); self::fail('Invalid layout accepted'); }
            catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
        }
        self::assertSame(['page_content_background' => '#ABCDEF'], BloxPageLayout::normalize(['page_content_background' => '#abcdef']));
    }

    public function testContextsNeverReuseAnEntityOrLanguageResult(): void
    {
        $a = BloxPageLayout::resolve(['page_content_max_width' => 960], ['type' => 'page', 'id' => 2, 'lang' => 'en'], ThemeSettings::defaults());
        $b = BloxPageLayout::resolve([], ['type' => 'page', 'id' => 2, 'lang' => 'ja'], ThemeSettings::defaults());
        self::assertSame('set', $a['sources']['page_content_max_width']);
        self::assertSame('inherit', $b['sources']['page_content_max_width']);
        self::assertSame('ja', $b['context']['lang']);
        self::assertSame('', BloxPageLayout::css($b));
        $this->expectException(InvalidArgumentException::class);
        BloxPageLayout::resolve([], ['type' => 'product', 'id' => 2, 'lang' => 'en']);
    }

    public function testPipelineKeepsExplicitValuesAndRejectsBadLayout(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxDocumentPipeline.php';
        $settings = ['page_header_hidden' => false, 'page_content_gutter' => 0, 'page_content_background' => null];
        self::assertEquals($settings, BloxDocumentPipeline::normalizeDocSettings($settings));
        self::assertSame([], BloxDocumentPipeline::normalizeDocSettings([]));
        $this->expectException(RuntimeException::class);
        BloxDocumentPipeline::normalizeDocSettings(['page_content_max_width' => '</style>']);
    }
}
