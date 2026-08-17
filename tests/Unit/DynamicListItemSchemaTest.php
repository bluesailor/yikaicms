<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DynamicListItemSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }
    public function testLegacyCardOutputRemainsByteCompatible(): void
    {
        $actual = \DynamicListItemSchema::render([]);
        $expected = '<a href="{yk:field name=url /}" class="group block bg-white rounded-lg border border-gray-100 shadow-sm hover:shadow-md transition overflow-hidden no-underline">'
            . '<div class="aspect-video overflow-hidden bg-gray-100"><img src="{yk:field name=cover /}" alt="{yk:field name=title /}" loading="lazy" class="w-full h-full object-cover"></div>'
            . '<div class="p-4"><h3 class="text-lg font-semibold mb-2 group-hover:text-primary transition">{yk:field name=title /}</h3>'
            . '<p class="text-sm text-gray-500">{yk:field name=summary len=80 /}</p></div></a>';

        $this->assertSame($expected, $actual);
    }

    public function testFieldOptionsAreNarrowedBySource(): void
    {
        $this->assertSame(['cover', 'none'], array_keys(\DynamicListItemSchema::fieldOptions('image')));
        $this->assertSame(['title', 'subtitle'], array_keys(\DynamicListItemSchema::fieldOptions('title', 'content')));
        $this->assertSame(['title', 'model'], array_keys(\DynamicListItemSchema::fieldOptions('title', 'product')));
        $this->assertSame(['summary', 'subtitle'], array_keys(\DynamicListItemSchema::fieldOptions('summary', 'product')));
        $this->assertSame(['date', 'publish_time', 'created_at', 'updated_at'], array_keys(\DynamicListItemSchema::fieldOptions('date', 'content')));
        $this->assertSame(['date', 'created_at', 'updated_at'], array_keys(\DynamicListItemSchema::fieldOptions('date', 'product')));
        $this->assertSame(['model', 'price', 'market_price', 'none'], array_keys(\DynamicListItemSchema::fieldOptions('meta', 'product')));
        $this->assertSame(['url', 'none'], array_keys(\DynamicListItemSchema::fieldOptions('link')));
        $this->assertSame([], \DynamicListItemSchema::fieldOptions('unknown'));
    }

    public function testSourceKindSupportsVisualAndLegacyData(): void
    {
        $this->assertSame('product', \DynamicListItemSchema::sourceKind(['query_source' => 'type:product']));
        $this->assertSame('content', \DynamicListItemSchema::sourceKind(['query_source' => 'type:case']));
        $this->assertSame('content', \DynamicListItemSchema::sourceKind(['query_source' => 'channel:8']));
        $this->assertSame('product', \DynamicListItemSchema::sourceKind(['source_type' => 'product']));
    }

    public function testMediaPresetUsesBoundFieldsWithoutLinkOrImage(): void
    {
        $html = \DynamicListItemSchema::render([
            'item_preset' => 'media',
            'image_field' => 'none',
            'title_field' => 'subtitle',
            'summary_field' => 'subtitle',
            'show_date' => true,
            'date_field' => 'updated_at',
            'link_field' => 'none',
        ]);

        $this->assertStringStartsWith('<div class="group flex flex-col sm:flex-row', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringContainsString('{yk:field name=subtitle /}', $html);
        $this->assertStringContainsString('{yk:field name=updated_at dateformat="Y-m-d" /}', $html);
    }

    public function testProductModelAndPositivePriceUseControlledConditionalMarkup(): void
    {
        $model = \DynamicListItemSchema::render([
            'query_source' => 'type:product',
            'show_meta' => true,
            'meta_field' => 'model',
        ]);
        $price = \DynamicListItemSchema::render([
            'query_source' => 'type:product',
            'show_meta' => true,
            'meta_field' => 'price',
        ]);
        $content = \DynamicListItemSchema::render([
            'query_source' => 'type:article',
            'show_meta' => true,
            'meta_field' => 'price',
        ]);

        $this->assertStringContainsString('{yk:if field=model op=notempty}', $model);
        $this->assertStringContainsString('{yk:field name=model /}', $model);
        $this->assertStringContainsString('{yk:if field=price op=gt value=0}', $price);
        $this->assertStringContainsString('{yk:field name=price /}</div>', $price);
        $this->assertStringNotContainsString('&yen;', $price); // r18 起货币符号随语言（formatPrice/currency_symbol）
        $this->assertStringNotContainsString('{yk:if field=price', $content);
    }

    public function testMinimalPresetKeepsControlledResponsiveMarkup(): void
    {
        $html = \DynamicListItemSchema::render([
            'item_preset' => 'minimal',
            'image_ratio' => 'square',
            'show_summary' => false,
        ]);

        $this->assertStringContainsString('group flex items-center gap-4 py-4 border-b', $html);
        $this->assertStringContainsString('w-24 shrink-0 aspect-square', $html);
        $this->assertStringNotContainsString('name=summary', $html);
    }

    public function testUnknownPresetAndFieldsFallBackWithoutTemplateInjection(): void
    {
        $html = \DynamicListItemSchema::render([
            'query_source' => 'type:product',
            'item_preset' => '<script>',
            'title_field' => 'title}{yk:config name=smtp_pass',
            'summary_field' => 'content esc=0',
            'date_field' => 'now',
            'link_field' => 'javascript',
            'show_meta' => true,
            'meta_field' => 'price}{yk:config name=smtp_pass',
        ]);

        $this->assertStringContainsString('group block bg-white', $html);
        $this->assertStringContainsString('{yk:field name=title /}', $html);
        $this->assertStringContainsString('{yk:field name=summary len=80 /}', $html);
        $this->assertStringContainsString('{yk:field name=model /}', $html);
        $this->assertStringNotContainsString('smtp_pass', $html);
        $this->assertStringNotContainsString('javascript', $html);
    }

    public function testEditorPreviewLimitDoesNotChangeFrontendLimit(): void
    {
        $element = new \ListDynamicElement();
        $data = [
            'query_source' => 'type:product',
            'limit' => 50,
            'template' => [['type' => 'text', 'data' => ['html' => 'x']]],
        ];

        $frontend = $element->buildMarkup($data);
        $preview = $element->buildMarkup($data, \ListDynamicElement::EDITOR_PREVIEW_LIMIT);

        $this->assertStringContainsString(' limit=50', $frontend);
        $this->assertStringContainsString(' limit=12', $preview);
        $this->assertStringNotContainsString(' limit=50', $preview);
    }

    public function testDynamicListCanHideEmptyStateWithoutChangingLegacyMessageMode(): void
    {
        $element = new \ListDynamicElement();
        $hidden = $element->buildMarkup([
            'empty_mode' => 'hidden',
            'empty' => 'Do not output',
            'template' => [['type' => 'text', 'data' => ['html' => 'x']]],
        ]);
        $legacy = $element->buildMarkup([
            'empty' => 'No results',
            'template' => [['type' => 'text', 'data' => ['html' => 'x']]],
        ]);

        $this->assertStringNotContainsString(' empty=', $hidden);
        $this->assertStringContainsString(' empty="No results"', $legacy);
    }

    public function testControlledChildrenBuildAFieldBoundLoopTemplate(): void
    {
        $html = (new \ListDynamicElement())->buildMarkup([
            'query_source' => 'type:product',
            'columns' => 2,
            'children' => [
                ['type' => 'heading', 'data' => ['text' => 'Static', 'loop_field' => 'model', 'level' => 'h3']],
                ['type' => 'text', 'data' => ['html' => '<p>Static</p>', 'loop_field' => 'summary', 'loop_length' => 42]],
                ['type' => 'image', 'data' => ['loop_field' => 'cover', 'loop_alt_field' => 'model', 'loop_link_field' => 'url']],
                ['type' => 'button', 'data' => ['loop_text_field' => 'model', 'loop_url_field' => 'url']],
                ['type' => 'div', 'data' => ['padding' => 'sm']],
                ['type' => 'code', 'data' => ['code' => '<script>bad()</script>']],
            ],
        ]);

        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2 gap-6', $html);
        $this->assertStringContainsString('<div class="yk-query-item">', $html);
        $this->assertStringContainsString('<h3 class="text-xl font-bold mb-4">{yk:field name=model /}</h3>', $html);
        $this->assertStringContainsString('{yk:field name=summary len=42 /}', $html);
        $this->assertStringContainsString('src="{yk:field name=cover /}"', $html);
        $this->assertStringContainsString('alt="{yk:field name=model /}"', $html);
        $this->assertStringContainsString('href="{yk:field name=url /}"', $html);
        $this->assertStringContainsString('<div class="yk-div p-3"></div>', $html);
        $this->assertStringNotContainsString('bad()', $html);
    }

    public function testControlledChildrenRejectUnknownBindingsAndNestedChildren(): void
    {
        $html = (new \ListDynamicElement())->buildMarkup([
            'query_source' => 'type:product',
            'children' => [[
                'type' => 'heading',
                'data' => [
                    'text' => 'Static fallback',
                    'loop_field' => 'subtitle}{yk:config name=smtp_pass',
                    'children' => [['type' => 'text', 'data' => ['html' => 'nested leak']]],
                ],
            ]],
        ]);

        $this->assertStringContainsString('Static fallback', $html);
        $this->assertStringNotContainsString('smtp_pass', $html);
        $this->assertStringNotContainsString('nested leak', $html);
    }

    public function testControlledChildrenKeepEditorSelectionPathInsideRepeatedMarkup(): void
    {
        $html = (new \ListDynamicElement())->buildMarkup([
            'children' => [[
                'type' => 'heading',
                'data' => ['loop_field' => 'title'],
            ]],
        ], null, [
            'edit_mode' => true,
            'path' => [2, 0, 1],
            'depth' => 0,
        ]);

        $this->assertStringContainsString('data-yk-el="2.0.1.0"', $html);
        $this->assertStringContainsString('data-yk-el-type="heading"', $html);
    }

    public function testControlledChildrenExposeSafeFieldFallbacks(): void
    {
        $html = (new \ListDynamicElement())->buildMarkup([
            'children' => [
                ['type' => 'heading', 'data' => ['loop_field' => 'title', 'loop_fallback' => 'Untitled']],
                ['type' => 'text', 'data' => ['loop_field' => 'summary', 'loop_fallback' => 'No summary']],
                ['type' => 'image', 'data' => [
                    'loop_field' => 'cover',
                    'loop_fallback' => '/assets/placeholder.jpg',
                    'loop_alt_field' => 'title',
                    'loop_alt_fallback' => 'Placeholder',
                ]],
            ],
        ]);

        $this->assertStringContainsString('{yk:field name=title fallback=Untitled /}', $html);
        $this->assertStringContainsString('{yk:field name=summary len=80 fallback=No%20summary /}', $html);
        $this->assertStringContainsString('{yk:field name=cover fallback=%2Fassets%2Fplaceholder.jpg /}', $html);
        $this->assertStringContainsString('{yk:field name=title fallback=Placeholder /}', $html);
    }

    public function testNumberedPaginationUsesAStablePerNodeParameter(): void
    {
        $element = new \ListDynamicElement();
        $data = ['pagination_mode' => 'numbers', 'limit' => 4];
        $first = $element->buildMarkup($data, null, ['node_id' => 'list-one']);
        $same = $element->buildMarkup($data, null, ['node_id' => 'list-one']);
        $second = $element->buildMarkup($data, null, ['node_id' => 'list-two']);

        $this->assertSame($first, $same);
        $this->assertMatchesRegularExpression('/page_param=ykq_[a-f0-9]{10}/', $first);
        $this->assertStringContainsString('{yk:list-pagination ', $first);
        $this->assertNotSame($first, $second);
    }
}
