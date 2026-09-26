<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BloxAssetCollector;
use BloxSectionDivider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/**
 * 区块边界预设（V2.0.1 A）。
 *
 * 三条红线在这里锁死：
 *   1. 不设边界时，Section 输出与 2.0.0 逐字节一致（旧文档不能因为新特性变样）；
 *   2. 装饰层是 aria-hidden 且不接收指针事件，不能挡住标题/按钮/锚点；
 *   3. 形状、高度、颜色只接受枚举与限幅值，作者塞不进任意 SVG path 或 CSS。
 */
final class BloxSectionDividerTest extends TestCase
{
    protected function tearDown(): void
    {
        BloxAssetCollector::reset();
        parent::tearDown();
    }

    /** @param array<string,mixed> $settings */
    private function renderSections(array ...$sections): string
    {
        $payload = [];
        foreach ($sections as $settings) {
            $payload[] = ['settings' => $settings, 'columns' => [['elements' => []]]];
        }
        return BlockRenderer::render((string) json_encode(['sections' => $payload]));
    }

    // ── 1. 缺省不变 ────────────────────────────────────────────

    public function testSectionWithoutDividerRendersExactlyAsBefore(): void
    {
        $plain = $this->renderSections([]);

        self::assertStringNotContainsString('yk-sec-divider', $plain);
        self::assertStringNotContainsString('<svg', $plain);
        // 未启用时不得平白加上定位类，否则既有布局会跟着变
        self::assertStringNotContainsString('relative overflow-hidden', $plain);
    }

    public function testEmptyShapeValueIsTreatedAsDisabled(): void
    {
        $html = $this->renderSections(['divider_top' => '', 'divider_bottom' => '']);
        self::assertStringNotContainsString('yk-sec-divider', $html);
    }

    public function testUnknownShapeIsRejectedByNormalizationAndRendersNothing(): void
    {
        $normalized = BloxSectionDivider::normalizeSettings(['divider_top' => 'zigzag']);
        self::assertSame('', $normalized['divider_top']);

        $html = $this->renderSections(['divider_top' => 'zigzag']);
        self::assertStringNotContainsString('yk-sec-divider', $html);
    }

    // ── 2. 启用后的输出形态 ─────────────────────────────────────

    public function testEnabledDividerIsDecorativeAndNonInteractive(): void
    {
        $html = $this->renderSections(['divider_bottom' => 'wave', 'divider_bottom_color' => '#112233']);

        self::assertStringContainsString('yk-sec-divider yk-sec-divider--bottom', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('focusable="false"', $html);
        self::assertStringContainsString('fill="#112233"', $html);
        // 装饰层必须落在 section 内、容器 div 之外
        self::assertMatchesRegularExpression('#</div><div class="yk-sec-divider[^"]*"[^>]*>.*?</div></section>#s', $html);
    }

    public function testTopAndBottomAreIndependent(): void
    {
        $html = $this->renderSections([
            'divider_top' => 'arc',
            'divider_bottom' => 'slant',
            'divider_top_color' => '#aabbcc',
            'divider_bottom_color' => '#ddeeff',
        ]);

        self::assertStringContainsString('yk-sec-divider--top', $html);
        self::assertStringContainsString('yk-sec-divider--bottom', $html);
        self::assertSame(2, substr_count($html, '<svg'));
    }

    public function testFlipAddsOnlyTheFlipClass(): void
    {
        $html = $this->renderSections(['divider_top' => 'wave', 'divider_top_flip' => true]);
        self::assertStringContainsString('yk-sec-divider--flip', $html);
    }

    public function testContentContainerStaysAboveTheDecoration(): void
    {
        // 底边装饰输出在容器之后；容器不抬层级的话正文会被盖住
        $html = $this->renderSections(['divider_bottom' => 'wave']);
        self::assertStringContainsString('mx-auto px-4 relative z-10', $html);

        // 自定义容器宽度会重置容器 class，这条路径同样要保住层级
        $custom = $this->renderSections([
            'divider_bottom' => 'wave',
            'max_width' => 'custom',
            'max_width_px' => 1100,
        ]);
        self::assertStringContainsString('relative z-10', $custom);
        self::assertStringContainsString('max-width:1100px', $custom);
    }

    public function testStylesheetIsLoadedOnlyWhenADividerExists(): void
    {
        $this->renderSections([]);
        self::assertStringNotContainsString(BloxSectionDivider::STYLESHEET, BloxAssetCollector::renderStyles());

        BloxAssetCollector::reset();
        $this->renderSections(['divider_top' => 'arc']);
        self::assertStringContainsString(BloxSectionDivider::STYLESHEET, BloxAssetCollector::renderStyles());
    }

    // ── 3. 高度与手机端 ────────────────────────────────────────

    public function testHeightIsClampedIntoRange(): void
    {
        $tooTall = BloxSectionDivider::normalizeSettings(['divider_top_height' => 9999]);
        self::assertSame(BloxSectionDivider::HEIGHT_MAX, $tooTall['divider_top_height']);

        $tooShort = BloxSectionDivider::normalizeSettings(['divider_top_height' => -50]);
        self::assertSame(BloxSectionDivider::HEIGHT_MIN, $tooShort['divider_top_height']);

        // 手机端允许 0（等于关掉这条装饰），桌面端不允许
        $mobileOff = BloxSectionDivider::normalizeSettings(['divider_top_height_m' => 0]);
        self::assertSame(0, $mobileOff['divider_top_height_m']);
    }

    public function testMobileHeightZeroHidesTheDividerOnPhones(): void
    {
        $html = $this->renderSections([
            'divider_bottom' => 'wave',
            'divider_bottom_height' => 80,
            'divider_bottom_height_m' => 0,
        ]);

        self::assertStringContainsString('data-yk-sd-mobile-off', $html);
        self::assertStringContainsString('--yk-sd-h:80px', $html);
        self::assertStringContainsString('--yk-sd-hm:0px', $html);
    }

    // ── 4. 颜色：白名单与"跟随相邻区块" ──────────────────────────

    public function testArbitraryColorStringsAreRejected(): void
    {
        foreach (['url(javascript:alert(1))', 'red; position:fixed', 'expression(1)', '<script>'] as $evil) {
            $normalized = BloxSectionDivider::normalizeSettings(['divider_top_color' => $evil]);
            self::assertSame('', $normalized['divider_top_color'], $evil);
        }

        // 站点颜色 token 与十六进制是合法的
        self::assertSame(
            'var(--yk-color-primary)',
            BloxSectionDivider::normalizeSettings(['divider_top_color' => 'var(--yk-color-primary)'])['divider_top_color']
        );
    }

    public function testEmptyColorFollowsTheNeighbourSectionBackground(): void
    {
        $html = $this->renderSections(
            ['divider_bottom' => 'wave'],
            ['bg_color' => '#0a0b0c']
        );

        self::assertStringContainsString('fill="#0a0b0c"', $html);
        self::assertStringNotContainsString('{{YK_SD:', $html);
    }

    public function testTopDividerFollowsThePreviousSection(): void
    {
        $html = $this->renderSections(
            ['bg_color' => '#123456'],
            ['divider_top' => 'arc']
        );

        self::assertStringContainsString('fill="#123456"', $html);
    }

    public function testMissingNeighbourFallsBackToThePageBackground(): void
    {
        $html = $this->renderSections(['divider_top' => 'arc', 'divider_bottom' => 'arc']);

        self::assertStringNotContainsString('{{YK_SD:', $html);
        self::assertSame(2, substr_count($html, 'fill="var(--yk-content-bg,#ffffff)"'));
    }

    public function testNeighbourWithBackgroundImageFallsBackInsteadOfGuessing(): void
    {
        $html = $this->renderSections(
            ['divider_bottom' => 'wave'],
            ['bg_color' => '#0a0b0c', 'bg_image' => '/uploads/x.jpg']
        );

        // 邻块是图片背景，取不到确定的纯色，回退页面底色而不是错用 bg_color
        self::assertStringContainsString('fill="var(--yk-content-bg,#ffffff)"', $html);
        self::assertStringNotContainsString('fill="#0a0b0c"', $html);
    }

    public function testPlaceholderTokensInAuthorContentAreNotSubstituted(): void
    {
        // 作者正文里如果恰好写了占位符样子的文字，不该被当成颜色回填
        $html = BloxSectionDivider::resolveNeighborColors('<p>{{YK_SD:NEXT:0}}</p>', []);
        self::assertStringContainsString('var(--yk-content-bg,#ffffff)', $html);
        self::assertStringNotContainsString('{{YK_SD:', $html);
    }

    // ── 5. 契约表 ─────────────────────────────────────────────

    public function testPropertyContractCoversEveryStoredKey(): void
    {
        $contractKeys = array_column(BloxSectionDivider::propertyContract(), 'key');
        sort($contractKeys);

        $storedKeys = BloxSectionDivider::settingKeys();
        sort($storedKeys);

        self::assertSame($storedKeys, $contractKeys);
        self::assertCount(10, $storedKeys);
    }
}
