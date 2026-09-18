<?php
/** 产品目录可组合排版：设置解析、区域契约与旧数据兼容。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BuilderRegistry;
use PHPUnit\Framework\TestCase;
use ProductCatalogElement;
use ProductCatalogLayout;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class ProductCatalogLayoutTest extends TestCase
{
    public function testLegacyDocumentWithoutNewFieldsStaysOnTheOldPath(): void
    {
        // 旧文档只有 layout/columns/show_* —— 必须返回 null，让元素走旧的 sidebar.php 路径。
        self::assertNull(ProductCatalogLayout::resolveMode([]));
        self::assertNull(ProductCatalogLayout::resolveMode([
            'layout' => 'sidebar', 'columns' => '3',
            'show_search' => true, 'show_categories' => true, 'show_sort' => true,
        ]));
    }

    public function testExplicitModeWins(): void
    {
        foreach (ProductCatalogLayout::MODES as $mode) {
            self::assertSame($mode, ProductCatalogLayout::resolveMode(['layout_mode' => $mode]));
        }
    }

    public function testUnknownModeIsIgnoredWhenNoModernFieldIsSet(): void
    {
        self::assertNull(ProductCatalogLayout::resolveMode(['layout_mode' => 'not-a-mode']));
    }

    public function testLegacyGridDerivesToolbarGridOnceANewFieldIsTouched(): void
    {
        self::assertSame('toolbar_grid', ProductCatalogLayout::resolveMode(['layout' => 'grid', 'card_style' => 'media']));
        self::assertSame('sidebar_grid', ProductCatalogLayout::resolveMode(['layout' => 'sidebar', 'show_summary' => true]));
        // 未设 layout 的旧文档默认跟随站点设置（测试桩回落 sidebar）
        self::assertSame('sidebar_grid', ProductCatalogLayout::resolveMode(['show_button' => true]));
    }

    public function testSettingsMapModeToSkeletonCardAndDefaults(): void
    {
        $sidebar = ProductCatalogLayout::settings([], 'sidebar_grid');
        self::assertSame('sidebar', $sidebar['nav']);
        self::assertSame('card', $sidebar['card']);
        self::assertSame(4, $sidebar['columns']);
        self::assertTrue($sidebar['show_search']);
        self::assertTrue($sidebar['show_pagination']);
        self::assertSame('landscape', $sidebar['image_ratio']);

        $toolbar = ProductCatalogLayout::settings([], 'toolbar_grid');
        self::assertSame('toolbar', $toolbar['nav']);

        // 图文列表/大图展示由模式决定卡片，忽略显式 card_style
        self::assertSame('media', ProductCatalogLayout::settings(['card_style' => 'featured'], 'media_list')['card']);
        self::assertSame('featured', ProductCatalogLayout::settings(['card_style' => 'card'], 'featured')['card']);
    }

    public function testSettingsHonourExplicitOverridesAndClampInvalidValues(): void
    {
        $s = ProductCatalogLayout::settings([
            'columns' => '3', 'card_style' => 'media', 'image_ratio' => 'portrait',
            'card_gap' => 'lg', 'content_align' => 'center', 'sidebar_width' => 'lg',
            'nav_position' => 'hidden', 'show_search' => false, 'show_button' => false,
            'empty_text' => '  没有产品  ', 'button_text' => '去看看',
        ], 'sidebar_grid');

        self::assertSame(3, $s['columns']);
        self::assertSame('media', $s['card']);
        self::assertSame('portrait', $s['image_ratio']);
        self::assertSame('lg', $s['gap']);
        self::assertSame('center', $s['align']);
        self::assertSame('lg', $s['sidebar_width']);
        self::assertSame('hidden', $s['nav']);
        self::assertFalse($s['show_search']);
        self::assertFalse($s['show_button']);
        self::assertSame('没有产品', $s['empty_text']);
        self::assertSame('去看看', $s['button_text']);

        // 非法枚举/列数回落默认
        $bad = ProductCatalogLayout::settings(['columns' => '9', 'image_ratio' => 'weird', 'card_gap' => 'x'], 'sidebar_grid');
        self::assertSame(4, $bad['columns']);
        self::assertSame('landscape', $bad['image_ratio']);
        self::assertSame('md', $bad['gap']);
    }

    public function testGridAndRatioClassesAreLiteralForTailwindScanning(): void
    {
        self::assertSame('grid grid-cols-1 md:grid-cols-2', ProductCatalogLayout::gridClasses(2));
        self::assertStringContainsString('lg:grid-cols-3', ProductCatalogLayout::gridClasses(3));
        self::assertStringContainsString('lg:grid-cols-4', ProductCatalogLayout::gridClasses(4));
        self::assertSame('aspect-square', ProductCatalogLayout::imageRatioClass('square'));
        self::assertSame('aspect-[3/4]', ProductCatalogLayout::imageRatioClass('portrait'));
        self::assertSame('lg:w-80', ProductCatalogLayout::sidebarWidthClass('lg'));
        self::assertSame('gap-8', ProductCatalogLayout::gapClass('lg'));
    }

    public function testRegionsDeclareStableNodesAndOnlyExistingControls(): void
    {
        $element = new ProductCatalogElement();
        $regions = $element->regions();

        self::assertSame(['toolbar', 'categories', 'list', 'pagination'], array_column($regions, 'key'));

        $controlKeys = array_map(
            static fn (array $c): string => (string) $c['key'],
            $element->controls()
        );
        $claimed = [];
        foreach ($regions as $region) {
            self::assertNotSame('', (string) $region['label'], $region['key'] . ' label');
            self::assertNotSame('', (string) $region['icon'], $region['key'] . ' icon');
            self::assertNotSame([], $region['keys'], $region['key'] . ' keys');
            foreach ($region['keys'] as $key) {
                self::assertContains($key, $controlKeys, $region['key'] . ' references unknown control ' . $key);
                $claimed[] = $key;
            }
        }
        // 每个控件都被某个区域认领：选中区域时不会出现“永远够不到”的控件
        foreach ($controlKeys as $key) {
            self::assertContains($key, $claimed, 'control not covered by any region: ' . $key);
        }
    }

    public function testRegistryExportsRegionsForTheEditorTree(): void
    {
        $meta = BuilderRegistry::meta('product');
        self::assertArrayHasKey('regions', $meta['product-catalog']);
        self::assertCount(4, $meta['product-catalog']['regions']);
        self::assertSame([], $meta['heading']['regions']);
    }
}
