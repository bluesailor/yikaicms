<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class TableElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testRegistrationAndSafeRoundTrip(): void
    {
        self::assertInstanceOf(TableElement::class, BuilderRegistry::get('table'));
        $data = ['grid' => ['rows' => [['Name', '<script>alert(1)</script>'], ['Value']], 'widths' => [120, '9999']], 'table_style' => 'invalid'];
        $json = json_encode([['columns' => [['elements' => [['type' => 'table', 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
        $document = json_decode(BloxDocumentPipeline::process($json, 'page')['json'], true, 512, JSON_THROW_ON_ERROR);
        $saved = $document['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame(['Value', ''], $saved['grid']['rows'][1]);
        self::assertSame([120, 800], $saved['grid']['widths']);
        self::assertSame('lines', $saved['table_style']);
        $html = (new TableElement())->render($saved);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('min-width:920px', $html);
        self::assertStringContainsString('tabindex="0"', $html);
    }

    public function testHeadersStylesAndMalformedInput(): void
    {
        $element = new TableElement();
        foreach (['lines', 'striped', 'bordered'] as $style) {
            $html = $element->render(['grid' => ['rows' => [['A', 'B'], ['C', 'D']]], 'table_style' => $style, 'header_column' => true]);
            self::assertStringContainsString('data-table-style="' . $style . '"', $html);
            self::assertSame(2, substr_count($html, 'scope="col"'));
            self::assertSame(1, substr_count($html, 'scope="row"'));
        }
        $html = $element->render(['grid' => null, 'header_row' => false, 'caption' => ['invalid']]);
        self::assertStringContainsString('<tbody><tr><td>', $html);
        self::assertStringNotContainsString('<thead>', $html);
        self::assertSame(['/assets/css/blox-table.css'], $element->styles());
        $grid = TableElement::normalizeGrid(['rows' => array_fill(0, 55, array_fill(0, 15, ['invalid'])), 'widths' => [[], -1, 1]]);
        self::assertCount(50, $grid['rows']);
        self::assertCount(12, $grid['rows'][0]);
        self::assertSame('', $grid['rows'][0][0]);
        self::assertSame([0, 0, 80], array_slice($grid['widths'], 0, 3));
    }

    public function testBorderSpacingIsBoundedAndOnlyUsedForSeparateBorders(): void
    {
        $element = new TableElement();
        $html = $element->render(['border_mode' => 'separate', 'cell_spacing' => 999, 'density' => 'roomy']);
        self::assertStringContainsString('border-separate', $html);
        self::assertStringContainsString('border-spacing:24px', $html);
        self::assertStringContainsString('data-density="roomy"', $html);
        $html = $element->render(['border_mode' => 'collapse', 'cell_spacing' => 12]);
        self::assertStringContainsString('border-spacing:0px', $html);
        $html = $element->render(['border_mode' => '"><script>', 'cell_spacing' => ['invalid']]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('border-collapse:collapse', $html);
        $control = array_values(array_filter($element->controls(), static fn (array $c): bool => $c['key'] === 'cell_spacing'))[0];
        self::assertSame(['terms' => [['border_mode', '=', 'separate']]], $control['visible_when']);
    }

    public function testCustomStylesUseSafeTokensAndLeaveContentUntouched(): void
    {
        $element = new TableElement();
        $data = ['grid' => ['rows' => [['Parameter', 'Value']], 'widths' => [100, 200]], 'custom_style' => true,
            'header_bg' => 'var(--yk-color-primary)', 'header_color' => '#ffffff', 'body_bg' => '#f0fdf4',
            'body_color' => '#14532d', 'stripe_color' => '#dcfce7', 'border_color' => '#166534',
            'border_width' => 2, 'padding_x' => 24, 'padding_y' => 8, 'header_align' => 'center', 'header_bold' => false];
        foreach (['lines', 'striped', 'bordered', 'brand', 'dark'] as $preset) {
            $data['table_style'] = $preset;
            $json = json_encode([['columns' => [['elements' => [['type' => 'table', 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
            $document = json_decode(BloxDocumentPipeline::process($json, 'page')['json'], true, 512, JSON_THROW_ON_ERROR);
            $saved = $document['sections'][0]['columns'][0]['elements'][0]['data'];
            self::assertSame($data['grid'], $saved['grid']);
            self::assertSame($preset, $saved['table_style']);
            $html = $element->render($saved);
            self::assertStringContainsString('--table-header-bg:var(--yk-color-primary)', $html);
            self::assertStringContainsString('--table-header-align:center;--table-header-weight:400', $html);
            self::assertStringContainsString('--table-padding-x:24px', $html);
        }
        $data['custom_style'] = false;
        self::assertStringNotContainsString('--table-header-bg:', $element->render($data));
        $data['custom_style'] = true;
        $data['header_bg'] = 'red;background:url(https://invalid.example)';
        $data['body_color'] = ['bad'];
        $data['padding_x'] = 999;
        $data['border_width'] = -10;
        $html = $element->render($data);
        self::assertStringNotContainsString('invalid.example', $html);
        self::assertStringContainsString('--table-padding-x:48px', $html);
        self::assertStringContainsString('--table-border-width:0px', $html);
    }
}
