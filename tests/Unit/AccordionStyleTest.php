<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class AccordionStyleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testStylesUseValidatedSelectAndSurviveDocumentRoundTrip(): void
    {
        $controls = array_column((new AccordionElement())->controls(), null, 'key');
        $control = $controls['faq_style'];
        self::assertSame('select', $control['type']);
        self::assertSame('style', $control['tab']);
        self::assertSame(['default', 'divided', 'soft'], array_keys($control['options']));
        foreach (['default', 'divided', 'soft', 'invalid', ['soft']] as $style) {
            $data = ['faq_style' => $style, 'items' => [['question' => 'Question?', 'answer' => 'Answer.']], 'open_first' => true, 'seo_schema' => true];
            $json = json_encode([['columns' => [['width' => 12, 'elements' => [['type' => 'accordion', 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
            $result = BloxDocumentPipeline::process($json, 'page');
            $document = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR);
            $saved = $document['sections'][0]['columns'][0]['elements'][0]['data'];
            self::assertSame(in_array($style, ['divided', 'soft'], true) ? $style : 'default', $saved['faq_style']);
            self::assertSame($data['items'], $saved['items']);
            self::assertSame('1', $saved['open_first']);
            self::assertSame('1', $saved['seo_schema']);
        }
    }

    public function testDefaultAndInvalidStylesKeepLegacyMarkup(): void
    {
        $element = new AccordionElement();
        $data = ['items' => "Question one|Answer one\nQuestion two|Answer two", 'open_first' => true, 'seo_schema' => true];
        $legacy = $element->render($data);
        foreach (['default', 'invalid', ['soft'], null] as $style) {
            self::assertSame($legacy, $element->render($data + ['faq_style' => $style]));
        }
        self::assertStringContainsString('divide-y divide-gray-200 border border-gray-200 rounded-xl bg-white overflow-hidden', $legacy);
        self::assertStringContainsString('<span>Question one</span>', $legacy);
        self::assertStringContainsString('ti-chevron-down', $legacy);
        self::assertStringNotContainsString('ti-plus', $legacy);
    }

    public function testNewStylesKeepNativeAccordionEscapingAndSchema(): void
    {
        foreach (['divided', 'soft'] as $style) {
            $data = [
                'faq_style' => $style, 'open_first' => true, 'seo_schema' => true,
                'items' => [
                    ['question' => '<img src=x onerror=alert(1)>', 'answer' => "</script>\nSecond line"],
                    ['question' => 'Another question?', 'answer' => 'Another answer.'],
                ],
            ];
            $html = (new AccordionElement())->render($data);
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($dom);
            self::assertSame(2, $xpath->query('//details/summary')->length);
            self::assertSame(1, $xpath->query('//details[@open]')->length);
            self::assertSame(0, $xpath->query('//img')->length);
            self::assertSame(2, $xpath->query('//summary/span')->length);
            self::assertSame(4, $xpath->query('//summary/i[@aria-hidden="true"]')->length);
            self::assertStringContainsString('data-blox-faq-style="' . $style . '"', $html);
            self::assertStringContainsString('&lt;/script&gt;<br />', $html);
            $schema = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('FAQPage', $schema['@type']);
            self::assertCount(2, $schema['mainEntity']);
            self::assertSame($data['items'][0]['answer'], $schema['mainEntity'][0]['acceptedAnswer']['text']);
            $closed = (new AccordionElement())->render(array_replace($data, ['open_first' => false, 'seo_schema' => false]));
            self::assertStringNotContainsString(' open>', $closed);
            self::assertStringNotContainsString('<script', $closed);
        }
    }

    public function testRichAnswersAreOptInSanitizedAndSaved(): void
    {
        $answer = '<p><strong>Support</strong> <a href="/contact.html" target="_blank">Contact</a></p><ul><li>One</li></ul>'
            . '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">Unsafe</a>';
        $items = [
            ['question' => 'Rich?', 'answer' => $answer, 'answer_format' => 'html'],
            ['question' => 'Plain?', 'answer' => '<strong>Literal</strong>'],
        ];
        $json = json_encode([['columns' => [['elements' => [['type' => 'accordion', 'data' => ['items' => $items]]]]]]], JSON_THROW_ON_ERROR);
        $document = json_decode(BloxDocumentPipeline::process($json, 'page')['json'], true, 512, JSON_THROW_ON_ERROR);
        $saved = $document['sections'][0]['columns'][0]['elements'][0]['data']['items'];
        self::assertSame('html', $saved[0]['answer_format']);
        self::assertStringNotContainsString('<script', $saved[0]['answer']);
        self::assertStringNotContainsString('javascript:', $saved[0]['answer']);
        self::assertStringNotContainsString('<img', $saved[0]['answer']);
        self::assertSame($items[1], $saved[1]);
        $html = (new AccordionElement())->render(['items' => $saved, 'seo_schema' => true]);
        self::assertStringContainsString('<strong>Support</strong>', $html);
        self::assertStringContainsString('<ul><li>One</li></ul>', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('&lt;strong&gt;Literal&lt;/strong&gt;', $html);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $schema = json_decode((new DOMXPath($dom))->query('//script')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($saved[0]['answer'], $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testNewIconsDoNotConsumeInlineQuestionFieldPaths(): void
    {
        foreach (['divided', 'soft'] as $style) {
            $json = json_encode([['columns' => [['elements' => [['type' => 'accordion', 'data' => [
                'faq_style' => $style, 'items' => "First question?|First answer.\nSecond question?|Second answer.",
            ]]]]]]], JSON_THROW_ON_ERROR);
            $previous = BlockRenderer::$homeFieldEditContext;
            try {
                BlockRenderer::$homeFieldEditContext = ['path' => '8.0.0', 'type' => 'custom:2', 'locale' => 'en'];
                $html = BlockRenderer::render($json);
            } finally {
                BlockRenderer::$homeFieldEditContext = $previous;
            }
            self::assertSame(4, substr_count($html, 'data-yk-home-inline="1"'));
            self::assertStringContainsString('data-yk-home-field="custom_overrides.en.0.columns.0.elements.0.data.accordion_items.1.question"', $html);
            self::assertStringContainsString('data-yk-home-field="custom_overrides.en.0.columns.0.elements.0.data.accordion_items.1.answer"', $html);
            self::assertStringNotContainsString('accordion_items.2.', $html);
        }
    }
}
