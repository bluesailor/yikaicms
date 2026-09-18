<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class TabsElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testRegistrationAndSafeRoundTrip(): void
    {
        self::assertInstanceOf(TabsElement::class, BuilderRegistry::get('tabs'));
        $items = [
            ['question' => '<b>Title</b>', 'answer' => '<p><strong>Safe</strong><script>alert(1)</script></p>', 'answer_format' => 'html'],
            ['question' => 'Plain', 'answer' => '<b>Literal</b>'],
        ];
        $json = json_encode([['columns' => [['elements' => [['type' => 'tabs', 'data' => ['items' => $items, 'tabs_style' => 'invalid']]]]]]], JSON_THROW_ON_ERROR);
        $result = json_decode(BloxDocumentPipeline::process($json, 'page')['json'], true, 512, JSON_THROW_ON_ERROR);
        $data = $result['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame('underline', $data['tabs_style']);
        self::assertStringNotContainsString('<script', $data['items'][0]['answer']);
        $html = (new TabsElement())->render($data);
        self::assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', $html);
        self::assertStringContainsString('<strong>Safe</strong>', $html);
        self::assertStringContainsString('&lt;b&gt;Literal&lt;/b&gt;', $html);
        self::assertStringNotContainsString('FAQPage', $html);
    }

    public function testUniqueAccessiblePanelsAndLimits(): void
    {
        $element = new TabsElement();
        foreach (['underline', 'segmented', 'bordered'] as $style) {
            $data = ['items' => array_fill(0, 15, ['question' => 'Tab', 'answer' => 'Content']), 'tabs_style' => $style, 'active_tab' => 99];
            $html = $element->render($data);
            $dom = new DOMDocument();
            @$dom->loadHTML($html . $element->render($data));
            $xpath = new DOMXPath($dom);
            self::assertSame(24, $xpath->query('//*[@role="tab"]')->length);
            self::assertSame(2, $xpath->query('//*[@aria-selected="true"]')->length);
            self::assertStringContainsString('data-active-tab="11"', $html);
            $ids = [];
            foreach ($xpath->query('//*[@id]') as $node) $ids[] = $node->getAttribute('id');
            self::assertSame($ids, array_values(array_unique($ids)));
            foreach ($xpath->query('//*[@role="tab"]') as $tab) {
                self::assertContains($tab->getAttribute('aria-controls'), $ids);
            }
        }
        self::assertSame('', $element->render(['items' => []]));
        self::assertSame(['/assets/js/blox-tabs.js'], $element->scripts());
    }
}
