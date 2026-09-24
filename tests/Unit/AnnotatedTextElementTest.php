<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AnnotatedTextElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function setUp(): void
    {
        BloxAssetCollector::resetForTests();
    }

    protected function tearDown(): void
    {
        BloxAssetCollector::resetForTests();
    }

    public function testElementIsAvailableInPaletteWithEditableControls(): void
    {
        $element = BuilderRegistry::get('annotated-text');
        self::assertInstanceOf(AnnotatedTextElement::class, $element);
        $meta = BuilderRegistry::meta()['annotated-text'];
        self::assertTrue($meta['paletteVisible']);
        self::assertSame('basic', $meta['category']);
        self::assertSame('text', $meta['treeLabelField']);
        self::assertSame(['text', 'mark_text', 'level', 'variant', 'mark_color', 'animate', 'draw_trigger', 'draw_speed'],
            array_column($meta['controls'], 'key'));
        self::assertSame(['underline', 'wavy', 'highlight', 'circle', 'box'],
            array_keys(array_column($meta['controls'], null, 'key')['variant']['options']));
    }

    public function testRendersFirstUnicodePhraseAndKeepsPlainTextReadable(): void
    {
        $element = new AnnotatedTextElement();
        $html = $element->render(['text' => '让想法被看见，想法会发光', 'mark_text' => '想法', 'variant' => 'circle']);
        self::assertStringStartsWith('<h2 ', $html);
        self::assertSame(1, substr_count($html, 'data-yk-annotated'));
        self::assertStringContainsString('yk-annotated-mark--circle', $html);
        self::assertStringContainsString('想法</span>', $html);
        self::assertStringContainsString('被看见，想法会发光', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('focusable="false"', $html);
        self::assertStringEndsWith('</h2>', $html);
    }

    public function testMissingPhraseAndUnsafeInputsFallBackSafely(): void
    {
        $element = new AnnotatedTextElement();
        $plain = $element->render(['text' => '<b>安全</b>', 'mark_text' => '不存在']);
        self::assertStringContainsString('&lt;b&gt;安全&lt;/b&gt;', $plain);
        self::assertStringNotContainsString('<svg', $plain);

        $html = $element->render([
            'text' => '<img onerror=alert(1)>安全', 'mark_text' => '安全',
            'level' => 'script', 'variant' => '" onmouseover="bad',
            'mark_color' => 'red;display:none', 'animate' => '0',
            'draw_speed' => 'invalid', 'draw_trigger' => 'invalid',
        ]);
        self::assertStringStartsWith('<h2 ', $html);
        self::assertStringContainsString('&lt;img onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img ', $html);
        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringNotContainsString('display:none', $html);
        self::assertStringContainsString('yk-annotated-mark--underline', $html);
        self::assertStringContainsString('data-annotation-animate="0"', $html);
        self::assertStringContainsString('data-annotation-trigger="viewport"', $html);
        self::assertStringContainsString('data-annotation-speed="normal"', $html);
    }

    public function testSavePipelineWhitelistsControlsAndAssetsAreLocal(): void
    {
        $sections = BloxDocumentPipeline::normalizeSections([['columns' => [['elements' => [[
            'type' => 'annotated-text', 'data' => [
                'text' => 'Hello world', 'mark_text' => 'world', 'variant' => 'not-real',
                'mark_color' => '#fff;display:none', 'animate' => false, 'draw_speed' => 'unknown',
            ],
        ]]]]]]);
        $data = $sections[0]['columns'][0]['elements'][0]['data'];
        self::assertSame('underline', $data['variant']);
        self::assertSame('', $data['mark_color']);
        self::assertSame('0', $data['animate']);
        self::assertSame('normal', $data['draw_speed']);

        $element = new AnnotatedTextElement();
        BloxAssetCollector::collectElement($element, $data);
        self::assertSame(['/assets/css/blox-annotated-text.css'], BloxAssetCollector::styles());
        self::assertSame([], BloxAssetCollector::scripts());
        BloxAssetCollector::collectElement($element, ['animate' => '1']);
        self::assertSame(['/assets/js/blox-annotated-text.js'], BloxAssetCollector::scripts());
    }

    public function testRuntimeKeepsStaticAndReducedMotionFallbacks(): void
    {
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/blox-annotated-text.css');
        $js = (string) file_get_contents(ROOT_PATH . '/assets/js/blox-annotated-text.js');
        self::assertStringContainsString('prefers-reduced-motion: reduce', $css);
        self::assertStringContainsString('IntersectionObserver', $js);
        self::assertStringContainsString("document.querySelector('.yk-canvas-region')", $js);
        self::assertStringContainsString('blox:content-updated', $js);
    }
}
