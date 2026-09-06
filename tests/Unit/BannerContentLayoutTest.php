<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BannerContentLayout;
use HomeBannerItemElement;
use HomeBloxBlockSchema;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BannerContentLayoutTest extends TestCase
{
    public function testLegacyContentKeepsThemeLayout(): void
    {
        self::assertSame('', BannerContentLayout::attributes([], []));
        self::assertSame('', BannerContentLayout::attributes(['banner_layout_desktop_position' => 'bottom-right'], []));
    }

    public function testGroupAndItemAndMobilePrecedence(): void
    {
        $group = ['banner_layout_desktop_enabled' => true, 'banner_layout_desktop_position' => 'top-left',
            'banner_layout_mobile_enabled' => true, 'banner_layout_mobile_position' => 'bottom-center'];
        $item = ['banner_layout_desktop_enabled' => true, 'banner_layout_desktop_position' => 'center-right'];
        $html = BannerContentLayout::attributes($item, $group);
        self::assertStringContainsString('--blox-layout-desktop-horizontal:flex-end;', $html);
        self::assertStringContainsString('--blox-layout-desktop-vertical:center;', $html);
        self::assertStringContainsString('--blox-layout-mobile-vertical:flex-end;', $html);
        self::assertStringContainsString('--blox-layout-mobile-horizontal:center;', $html);
        $item['banner_layout_mobile_enabled'] = true;
        $item['banner_layout_mobile_position'] = 'top-right';
        self::assertStringContainsString('--blox-layout-mobile-horizontal:flex-end;', BannerContentLayout::attributes($item, $group));
        $item['banner_layout_desktop_enabled'] = false;
        self::assertStringContainsString('--blox-layout-desktop-vertical:flex-start;', BannerContentLayout::attributes($item, $group));
    }

    public function testMobileOnlyOverrideDoesNotChangeDesktopAndInheritedOffsetsAreBounded(): void
    {
        $html = BannerContentLayout::attributes(['banner_layout_mobile_enabled' => true], []);
        self::assertStringNotContainsString('data-blox-layout-desktop', $html);
        self::assertStringContainsString('data-blox-layout-mobile', $html);
        $html = BannerContentLayout::attributes([], ['banner_layout_desktop_enabled' => true, 'banner_layout_desktop_x' => 160]);
        self::assertStringContainsString('--blox-layout-desktop-x:160px;', $html);
        self::assertStringContainsString('--blox-layout-mobile-x:48px;', $html);
    }

    public function testNormalizationRejectsCssInjectionAndCompoundValues(): void
    {
        $data = BannerContentLayout::normalize([
            'banner_layout_desktop_enabled' => ['true'],
            'banner_layout_desktop_x' => '1; background:url(https://example.com)',
            'banner_layout_desktop_width' => [],
            'banner_layout_desktop_position' => [],
            'banner_layout_mobile_buttons' => 'left; color:red',
            'banner_layout_mobile_y' => 999999,
        ]);
        self::assertFalse($data['banner_layout_desktop_enabled']);
        self::assertSame(0, $data['banner_layout_desktop_x']);
        self::assertSame(720, $data['banner_layout_desktop_width']);
        self::assertSame('center-center', $data['banner_layout_desktop_position']);
        self::assertSame('center', $data['banner_layout_mobile_buttons']);
        self::assertSame(48, $data['banner_layout_mobile_y']);
    }

    public function testLayoutSurvivesBothNormalizationPaths(): void
    {
        $input = ['block_type' => 'banner', 'enabled' => true, 'banner_layout_desktop_enabled' => true,
            'banner_layout_desktop_position' => 'bottom-left', 'banner_layout_desktop_x' => -40];
        foreach ([HomeBannerItemElement::normalize($input), HomeBloxBlockSchema::normalize($input)] as $normalized) {
            self::assertTrue($normalized['banner_layout_desktop_enabled']);
            self::assertSame('bottom-left', $normalized['banner_layout_desktop_position']);
            self::assertSame(-40, $normalized['banner_layout_desktop_x']);
        }
    }

    public function testEveryPositionProducesExpectedAxes(): void
    {
        foreach (['top' => 'flex-start', 'center' => 'center', 'bottom' => 'flex-end'] as $v => $expectedV) {
            foreach (['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'] as $h => $expectedH) {
                $html = BannerContentLayout::attributes([], ['banner_layout_desktop_enabled' => true,
                    'banner_layout_desktop_position' => $v . '-' . $h]);
                self::assertStringContainsString('--blox-layout-desktop-vertical:' . $expectedV . ';', $html);
                self::assertStringContainsString('--blox-layout-desktop-horizontal:' . $expectedH . ';', $html);
            }
        }
    }
}

