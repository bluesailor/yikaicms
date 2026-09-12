<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class BasicContentVariantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testAlertDefaultsAndVariants(): void
    {
        $el = new AlertElement();
        $default = '<div class="border rounded-lg px-4 py-3 my-2 bg-blue-50 text-blue-700 border-blue-200">&lt;b&gt;Copy&lt;/b&gt;</div>';
        self::assertSame($default, $el->render(['text' => '<b>Copy</b>']));
        self::assertSame($default, $el->render(['text' => '<b>Copy</b>', 'level' => [], 'alert_style' => 'bad', 'show_icon' => 'false']));
        foreach (['soft', 'outline', 'solid'] as $style) {
            $html = $el->render(['text' => '<strong>Saved</strong><a href="javascript:alert(1)">Link</a><script>alert(1)</script>',
                'text_format' => 'html', 'level' => 'success', 'alert_style' => $style, 'show_icon' => true]);
            self::assertStringContainsString('yk-alert-success yk-alert-' . $style, $html);
            self::assertStringContainsString('ti-circle-check', $html);
            self::assertStringContainsString('aria-hidden="true"', $html);
            self::assertStringContainsString('<strong>Saved</strong>', $html);
            self::assertStringNotContainsString('javascript:', $html);
            self::assertStringNotContainsString('<script', $html);
        }
    }

    public function testQuoteDefaultsAndVariants(): void
    {
        $el = new QuoteElement();
        $default = '<blockquote class="border-l-4 border-primary pl-4 py-2 my-4 italic text-gray-600">Copy<footer class="mt-2 text-sm not-italic text-gray-400">— Author</footer></blockquote>';
        self::assertSame($default, $el->render(['text' => 'Copy', 'author' => 'Author']));
        self::assertSame($default, $el->render(['text' => 'Copy', 'author' => 'Author', 'quote_style' => []]));
        foreach (['soft', 'center'] as $style) {
            $html = $el->render(['text' => '<em>Quote</em><img src=x onerror=alert(1)>', 'text_format' => 'html',
                'author' => '<b>Author</b>', 'quote_style' => $style]);
            self::assertStringContainsString('yk-quote yk-quote-' . $style, $html);
            self::assertStringContainsString('<em>Quote</em>', $html);
            self::assertStringContainsString('&lt;b&gt;Author&lt;/b&gt;', $html);
            self::assertStringNotContainsString('onerror', $html);
            self::assertStringNotContainsString('border-l-4', $html);
        }
        self::assertStringNotContainsString('<footer', $el->render(['quote_style' => 'center']));
    }

    public function testDividerLengthAndStyleWhitelist(): void
    {
        $el = new DividerElement();
        $default = '<hr class="my-4 border-0" style="border-top:1px solid #e5e7eb">';
        self::assertSame($default, $el->render([]));
        self::assertSame($default, $el->render(['style' => 'solid;position:fixed', 'line_length' => [], 'line_align' => []]));
        $html = $el->render(['style' => 'dotted', 'width' => 8, 'color' => '#112233', 'spacing' => 'lg', 'line_length' => 'short', 'line_align' => 'right']);
        self::assertStringContainsString('my-8 border-0', $html);
        self::assertStringContainsString('border-top:3px dotted #112233;width:80px;max-width:100%;margin-left:auto;margin-right:0', $html);
        self::assertStringContainsString('width:50%;max-width:100%;margin-left:auto;margin-right:auto', $el->render(['line_length' => 'half']));
        self::assertStringNotContainsString('width:', $el->render(['line_length' => 'full', 'line_align' => 'right']));
    }

    public function testVariantSettingsRoundTripThroughDocument(): void
    {
        $elements = [
            ['type' => 'alert', 'data' => ['text' => '<strong>Copy</strong>', 'text_format' => 'html', 'level' => 'warning', 'alert_style' => 'outline', 'show_icon' => true]],
            ['type' => 'quote', 'data' => ['text' => '<em>Quote</em>', 'text_format' => 'html', 'quote_style' => 'center', 'author' => 'Author']],
            ['type' => 'divider', 'data' => ['style' => 'dashed', 'line_length' => 'half', 'line_align' => 'left']],
        ];
        $result = BloxDocumentPipeline::process(json_encode([['columns' => [['elements' => $elements]]]], JSON_THROW_ON_ERROR), 'page');
        $saved = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR)['sections'][0]['columns'][0]['elements'];
        foreach ($elements as $index => $element) {
            foreach ($element['data'] as $key => $value) {
                self::assertSame($key === 'show_icon' ? '1' : $value, $saved[$index]['data'][$key]);
            }
        }
        foreach ([new AlertElement(), new QuoteElement()] as $el) {
            $controls = array_column($el->controls(), null, 'key');
            self::assertTrue($controls['text']['compact_richtext']);
            self::assertTrue($controls['text_format']['editor_hidden']);
        }
    }
}
