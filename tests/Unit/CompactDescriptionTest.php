<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class CompactDescriptionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testPlainDescriptionsNeverInterpretMarkup(): void
    {
        $text = '<strong>Literal</strong> & &amp; < 5';
        foreach (['card', 'icon-box'] as $type) {
            foreach ([null, 'plain', 'HTML', 'invalid', [], ['html']] as $format) {
                $data = ['text' => $text];
                if ($format !== null) $data['text_format'] = $format;
                $saved = $this->normalize($type, $data);
                self::assertSame($text, $saved['text']);
                $html = BuilderRegistry::get($type)->render($saved);
                self::assertStringContainsString('<p class="text-sm text-gray-500">' . htmlspecialchars($text) . '</p>', $html);
                self::assertStringNotContainsString('yk-description', $html);
            }
        }
    }

    public function testSafeFormattingSurvivesRoundTrip(): void
    {
        $text = '<p>Text <strong>bold</strong> <em>italic</em> <u>underlined</u><br>Next</p><ul><li>One</li><li><span style="color:#2563eb;background-color:rgb(253, 230, 138)">Two</span></li></ul>';
        foreach (['card', 'icon-box'] as $type) {
            $saved = $this->normalize($type, ['text' => $text, 'text_format' => 'html']);
            self::assertSame('html', $saved['text_format']);
            self::assertStringContainsString('<strong>bold</strong>', $saved['text']);
            self::assertStringContainsString('color: #2563eb', $saved['text']);
            self::assertStringContainsString('background-color: rgb(253, 230, 138)', $saved['text']);
            self::assertSame($saved, $this->normalize($type, $saved));
            $html = BuilderRegistry::get($type)->render($saved);
            self::assertStringContainsString('<div class="text-sm text-gray-500 yk-description"><p>', $html);
            self::assertStringNotContainsString('<p class="text-sm text-gray-500"><p>', $html);
        }
    }

    public function testUnsafeContentIsFilteredOnSaveAndDirectRender(): void
    {
        $payloads = [
            '<script>alert(1)</script><p onclick="alert(1)">Safe</p>',
            '<svg><a href="javascript:alert(1)">Unsafe</a></svg><p>Safe</p>',
            '<iframe src="https://www.youtube.com/embed/x"></iframe><img src="https://example.com/tracker" onerror="alert(1)"><p>Safe</p>',
            '<span class="fixed" id="trap" style="position:fixed;inset:0;z-index:9999;color:expression(alert(1));background-image:url(https://example.com/a)">Safe</span>',
            '<a href="jav&#x61;script:alert(1)" onmouseover="alert(1)">Safe</a>',
            '<a href="data:text/html,evil">Safe</a><form><button>Unsafe</button></form>',
            '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>"></table></mtext></math><p>Safe</p>',
        ];
        foreach (['card', 'icon-box'] as $type) {
            foreach ($payloads as $payload) {
                $raw = ['text' => $payload, 'text_format' => 'html'];
                $saved = $this->normalize($type, $raw);
                foreach ([$saved['text'], BuilderRegistry::get($type)->render($raw)] as $html) {
                    self::assertDoesNotMatchRegularExpression('/<(?:script|iframe|svg|math|img|form|input|button)\b|\bon(?:click|error|mouseover)\s*=|(?:javascript|data):|position\s*:|expression\s*\(|url\s*\(/i', $html);
                }
                self::assertSame($saved['text'], HtmlPolicy::description($saved['text']));
            }
        }
    }

    public function testLinkedCardsOnlyStripAnchorsAtRenderTime(): void
    {
        $data = ['text_format' => 'html', 'text' => '<p>Read <a href="/details.html" target="_blank">details <strong>now</strong></a></p>', 'link' => '/contact.html'];
        $saved = $this->normalize('card', $data);
        self::assertStringContainsString('<a href="/details.html"', $saved['text']);
        self::assertStringContainsString('rel="noopener noreferrer"', $saved['text']);
        $html = (new CardElement())->render($saved);
        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('details <strong>now</strong>', $html);
        foreach (['', 'javascript:alert(1)'] as $outerLink) {
            $saved['link'] = $outerLink;
            $html = (new CardElement())->render($saved);
            self::assertStringStartsWith('<div ', $html);
            self::assertStringContainsString('<a href="/details.html"', $html);
        }
    }

    public function testEmptyInvalidAndOversizedValuesStaySafe(): void
    {
        foreach (['card', 'icon-box'] as $type) {
            foreach (['', [], null, '<script>only unsafe</script>'] as $value) {
                $saved = $this->normalize($type, ['text' => $value, 'text_format' => 'html']);
                self::assertSame('', $saved['text']);
                self::assertStringNotContainsString('yk-description', BuilderRegistry::get($type)->render($saved));
            }
            $saved = $this->normalize($type, ['text' => '<p>' . str_repeat('x', 21000) . '</p>', 'text_format' => 'html']);
            self::assertLessThanOrEqual(20000, mb_strlen(strip_tags($saved['text'])));
            self::assertSame($saved, $this->normalize($type, $saved));
        }
    }

    public function testGeneralRichTextPolicyIsNotRestrictedByDescriptionProfile(): void
    {
        $html = '<p>' . str_repeat('a & ', 3000) . 'TAIL</p>';
        $saved = $this->normalize('card', ['text' => $html, 'text_format' => 'html']);
        self::assertLessThanOrEqual(20000, mb_strlen($saved['text']));
        self::assertSame($saved, $this->normalize('card', $saved));
        self::assertSame($saved['text'], HtmlPolicy::description($saved['text']));
        $this->assertGeneralRichTextPolicy();
    }

    private function assertGeneralRichTextPolicy(): void
    {
        $html = '<h2 class="text-center">Heading</h2><img src="/test.jpg"><table><tr><td>Cell</td></tr></table>';
        self::assertStringContainsString('<h2 class="text-center">', HtmlPolicy::richText($html));
        self::assertStringContainsString('<img src="/test.jpg">', HtmlPolicy::richText($html));
        self::assertStringContainsString('<table>', HtmlPolicy::richText($html));
        self::assertStringNotContainsString('<h2', HtmlPolicy::description($html));
        self::assertStringNotContainsString('<img', HtmlPolicy::description($html));
        self::assertStringNotContainsString('<table', HtmlPolicy::description($html));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalize(string $type, array $data): array
    {
        $sections = BloxDocumentPipeline::normalizeSections([['id' => 'description-test', 'columns' => [
            ['width' => 12, 'elements' => [['id' => 'desc', 'type' => $type, 'data' => $data]]],
        ]]]);
        return $sections[0]['columns'][0]['elements'][0]['data'];
    }
}
