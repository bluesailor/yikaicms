<?php

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class OrgChartElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testElementIsRegisteredWithStructuredNodeControlAndLocalAssets(): void
    {
        $element = BuilderRegistry::get('org-chart');
        self::assertInstanceOf(OrgChartElement::class, $element);

        $controls = array_column($element->controls(), null, 'key');
        self::assertSame('org_repeater', $controls['nodes']['type']);
        self::assertSame(100, $controls['nodes']['max']);
        self::assertCount(13, $controls['nodes']['default']);
        self::assertSame([
            '/assets/d3/d3.min.js',
            '/assets/d3-flextree/d3-flextree.min.js',
            '/assets/d3-org-chart/d3-org-chart.min.js',
            '/assets/js/blox-org-chart.js',
        ], $element->scripts());
        self::assertSame(['/assets/css/blox-org-chart.css'], $element->styles());
        foreach (array_merge($element->scripts(), $element->styles()) as $asset) {
            self::assertFileExists(ROOT_PATH . $asset);
        }
    }

    public function testRenderNormalizesHierarchyAndEscapesNodeContent(): void
    {
        $element = new OrgChartElement();
        $html = $element->render([
            'nodes' => [
                ['id' => 'root', 'parent_id' => '', 'name' => '<Root>', 'title' => 'CEO'],
                ['id' => 'child', 'parent_id' => 'missing', 'name' => 'R&D', 'title' => '</script><b>'],
            ],
            'style' => 'purple',
            'layout' => 'left',
            'compact' => true,
            'initial_depth' => 99,
        ]);

        self::assertStringContainsString('data-blox-org-chart', $html);
        self::assertStringContainsString('yk-org-style-purple', $html);
        self::assertStringContainsString('&lt;Root&gt;', $html);
        self::assertStringNotContainsString('</script><b>', $html);

        preg_match('/<script type="application\/json" data-org-chart-data>(.*?)<\/script>/s', $html, $match);
        $payload = json_decode($match[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('root', $payload['nodes'][1]['parent_id']);
        self::assertSame('left', $payload['layout']);
        self::assertTrue($payload['compact']);
        self::assertSame(8, $payload['initial_depth']);
    }

    public function testLegacyNestedHtmlIsConvertedAndRemainingContentIsPreserved(): void
    {
        $legacy = '<div class="org-chart org-style-teal"><ul><li>'
            . '<div class="org-node org-ceo">Alice<span class="org-title">CEO</span></div>'
            . '<ul><li><div class="org-node org-vp">Technology<span class="org-title">VP</span></div>'
            . '<ul><li><div class="org-node org-dept">R&amp;D</div></li></ul>'
            . '</li></ul></li></ul></div><p>Organization note</p>';

        $converted = OrgChartElement::extractLegacyHtml($legacy);
        self::assertNotNull($converted);
        self::assertSame('teal', $converted['style']);
        self::assertSame(['Alice', 'Technology', 'R&D'], array_column($converted['nodes'], 'name'));
        self::assertSame('', $converted['nodes'][0]['parent_id']);
        self::assertSame('org_1', $converted['nodes'][1]['parent_id']);
        self::assertSame('org_2', $converted['nodes'][2]['parent_id']);
        self::assertStringContainsString('Organization note', $converted['remaining_html']);
        self::assertStringNotContainsString('org-chart', $converted['remaining_html']);
    }
}
