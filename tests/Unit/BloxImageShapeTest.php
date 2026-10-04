<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use BloxImageShape;
use ImageElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/**
 * 图片形状与非对称圆角（V2.0.1 B）。
 *
 * 任务书的硬约束在这里锁死：形状只改 border-radius，不得让链接命中区、灯箱、
 * alt、srcset、裁切失效；也不得改变旧图片的默认圆角。
 */
final class BloxImageShapeTest extends TestCase
{
    protected function tearDown(): void
    {
        BloxAssetCollector::reset();
        parent::tearDown();
    }

    /** @param array<string,mixed> $extra */
    private function renderImage(array $extra = []): string
    {
        return (new ImageElement())->render(['src' => '/uploads/demo.jpg', 'alt' => 'demo'] + $extra);
    }

    // ── 1. 旧图片不受影响 ───────────────────────────────────────

    public function testImageWithoutShapeKeepsTheDefaultRoundedClass(): void
    {
        $html = $this->renderImage();

        self::assertStringContainsString('class="w-full rounded-lg"', $html);
        self::assertStringNotContainsString('yk-img-shape', $html);
        self::assertStringNotContainsString('border-radius', $html);
    }

    public function testRadiusModeDefaultKeepsTheElementDefault(): void
    {
        // 作者碰过圆角输入框但模式仍是"沿用默认"：不能写出 border-radius:0 把默认圆角吃掉
        $html = $this->renderImage(['image_radius_mode' => '', 'image_radius_tl' => 0, 'image_radius_br' => 0]);
        self::assertStringNotContainsString('border-radius', $html);
    }

    // ── 2. 形状 ────────────────────────────────────────────────

    public function testShapeAddsTwoClassNamesSoItBeatsTailwindRounded(): void
    {
        $html = $this->renderImage(['image_shape' => 'arch']);

        // 两个类名的选择器（.yk-img-shape.yk-img-shape--arch）特异性压过 .rounded-lg
        self::assertStringContainsString('class="w-full rounded-lg yk-img-shape yk-img-shape--arch"', $html);
    }

    public function testSideShapeCarriesTheChosenCorner(): void
    {
        $html = $this->renderImage(['image_shape' => 'side', 'image_shape_side' => 'br']);
        self::assertStringContainsString('yk-img-shape--side-br', $html);
    }

    public function testInvalidSideFallsBackToTopLeft(): void
    {
        $html = $this->renderImage(['image_shape' => 'side', 'image_shape_side' => 'nope']);
        self::assertStringContainsString('yk-img-shape--side-tl', $html);
    }

    public function testUnknownShapeIsRejected(): void
    {
        self::assertSame('', BloxImageShape::normalizeData(['image_shape' => 'star'])['image_shape']);
        self::assertStringNotContainsString('yk-img-shape', $this->renderImage(['image_shape' => 'star']));
    }

    public function testStylesheetLoadsOnlyWhenAShapeIsUsed(): void
    {
        $this->renderImage();
        self::assertStringNotContainsString(BloxImageShape::STYLESHEET, BloxAssetCollector::renderStyles());

        BloxAssetCollector::reset();
        $this->renderImage(['image_shape' => 'organic']);
        self::assertStringContainsString(BloxImageShape::STYLESHEET, BloxAssetCollector::renderStyles());
    }

    // ── 3. 四角半径 ────────────────────────────────────────────

    public function testAllCornersModeEmitsASingleRadius(): void
    {
        $html = $this->renderImage(['image_radius_mode' => 'all', 'image_radius_all' => 32]);
        self::assertStringContainsString('border-radius:32px!important;', $html);
    }

    public function testPerCornerModeEmitsFourValuesInCssOrder(): void
    {
        $html = $this->renderImage([
            'image_radius_mode' => 'custom',
            'image_radius_tl' => 10, 'image_radius_tr' => 20,
            'image_radius_br' => 30, 'image_radius_bl' => 40,
        ]);
        self::assertStringContainsString('border-radius:10px 20px 30px 40px!important;', $html);
    }

    public function testPerCornerModeCanMakeSquareCorners(): void
    {
        // 模式显式选了，就算四个都是 0 也是作者的本意：要直角
        $html = $this->renderImage(['image_radius_mode' => 'custom']);
        self::assertStringContainsString('border-radius:0px 0px 0px 0px!important;', $html);
    }

    public function testRadiusIsClamped(): void
    {
        $normalized = BloxImageShape::normalizeData(['image_radius_all' => 99999, 'image_radius_tl' => -5]);
        self::assertSame(BloxImageShape::RADIUS_MAX, $normalized['image_radius_all']);
        self::assertSame(0, $normalized['image_radius_tl']);
    }

    public function testShapeWinsOverCornerRadii(): void
    {
        $html = $this->renderImage([
            'image_shape' => 'arch',
            'image_radius_mode' => 'custom',
            'image_radius_tl' => 40,
        ]);

        self::assertStringContainsString('yk-img-shape--arch', $html);
        self::assertStringNotContainsString('border-radius', $html);
    }

    /**
     * 真实模板的主题 CSS 普遍带 `#eg-hero img{border-radius:...}` 这种 ID 选择器
     * （特异性 1,0,1），两个类名压不过去。2026-09-26 在 yikai-jp-engei 上实测：
     * 作者选了拱顶，页面纹丝不动。形状与四角值都必须带 !important。
     */
    public function testShapeBeatsThemeIdSelectors(): void
    {
        // 先去注释：说明文字里也写着 border-radius，会把统计带偏
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(ROOT_PATH . '/assets/css/yikay-image-shape.css'));
        $declarations = substr_count($css, 'border-radius:');
        self::assertGreaterThan(0, $declarations);
        self::assertSame($declarations, substr_count($css, '!important'), '每条形状声明都要带 !important');

        $inline = $this->renderImage(['image_radius_mode' => 'all', 'image_radius_all' => 8]);
        self::assertStringContainsString('!important', $inline);
    }

    // ── 4. 不得破坏的既有行为 ───────────────────────────────────

    public function testShapeKeepsLinkLightboxAltAndResponsiveAttributes(): void
    {
        $html = $this->renderImage([
            'image_shape' => 'arch',
            'click_action' => 'lightbox',
            'lightbox_group' => 'gallery',
        ]);

        self::assertStringContainsString('data-lightbox="gallery"', $html);
        self::assertStringContainsString('alt="demo"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('yk-img-shape--arch', $html);
        // 遮罩类方案会动命中区，这里必须一个都不出现
        self::assertStringNotContainsString('clip-path', $html);
        self::assertStringNotContainsString('mask', $html);
    }

    public function testShapeCoexistsWithCroppingAndPreset(): void
    {
        $html = $this->renderImage([
            'image_shape' => 'organic',
            'image_ratio' => 'wide',
            'image_fit' => 'cover',
        ]);

        self::assertStringContainsString('aspect-ratio:16 / 9', $html);
        self::assertStringContainsString('object-fit:cover', $html);
        self::assertStringContainsString('yk-img-shape--organic', $html);
    }

    public function testCornerRadiusIsAppendedAfterThePresetSoItWins(): void
    {
        // avatar 预设自带 border-radius:9999px；作者的四角值排在后面才能盖住它
        $html = $this->renderImage([
            'image_preset' => 'avatar',
            'image_radius_mode' => 'all',
            'image_radius_all' => 12,
        ]);

        $presetPos = strpos($html, 'border-radius:9999px');
        $customPos = strpos($html, 'border-radius:12px!important');
        self::assertIsInt($presetPos);
        self::assertIsInt($customPos);
        self::assertGreaterThan($presetPos, $customPos);
    }

    public function testOverlayFollowsTheSameShape(): void
    {
        $html = $this->renderImage([
            'image_preset' => 'overlay',
            'overlay_title' => 'Title',
            'image_shape' => 'arch',
        ]);

        // 图片与遮罩各有一处形状类，直角渐变不能露在拱顶之外
        self::assertSame(2, substr_count($html, 'yk-img-shape--arch'));
    }

    // ── 5. 契约表 ─────────────────────────────────────────────

    public function testContractCoversEveryStoredKeyAndControl(): void
    {
        $contractKeys = array_column(BloxImageShape::propertyContract(), 'key');
        sort($contractKeys);

        $storedKeys = BloxImageShape::dataKeys();
        sort($storedKeys);
        self::assertSame($storedKeys, $contractKeys);

        // 控件不得出现重复 key：编辑器按 key 建组件，撞 key 会串
        $controlKeys = array_column(BloxImageShape::controls(), 'key');
        self::assertSame(array_unique($controlKeys), $controlKeys);
        sort($controlKeys);
        self::assertSame($storedKeys, $controlKeys);
    }
}
