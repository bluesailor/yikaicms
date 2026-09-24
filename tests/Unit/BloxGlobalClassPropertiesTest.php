<?php
/**
 * V2.0.0 全局类首批属性契约（BloxGlobalClasses::propertyContract）：白名单归一、
 * 分档输出、简写/分边顺序、只设单档的区间规则、宽屏开关、特异性后缀与多类顺序。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxGlobalClasses;
use BloxResponsiveValue;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxGlobalClassPropertiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BloxGlobalClasses::resetForTests();
    }

    protected function tearDown(): void
    {
        BloxResponsiveValue::overrideWideEnabled(null);
        parent::tearDown();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_global_classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '',
                settings TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                trashed_at INTEGER NOT NULL DEFAULT 0,
                modified INTEGER NOT NULL DEFAULT 0,
                revision INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_class_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testFirstBatchSettingsAreWhitelistedAndRangeChecked(): void
    {
        $normalized = BloxGlobalClasses::normalizeSettings([
            'margin_top_px' => ['d' => 24, 'm' => 12],
            'margin_bottom_px' => -40,
            'margin_left_px' => 401,
            'padding_top_px' => '32',
            'padding_right_px' => -1,
            'font_weight' => 700,
            'text_align' => 'center',
            'border_width_px' => 2,
            'radius_px' => 12,
            'width_pct' => 50,
            'max_width_px' => 720,
            'justify_content' => 'space-between',
            'align_items' => 'center',
            // 非法值：丢弃，不进样式表
            'font_size_px' => '16px;}body{x:y',
            'line_height' => 9,
        ]);

        self::assertSame(['d' => 24, 'm' => 12], $normalized['margin_top_px']);
        self::assertSame(-40, $normalized['margin_bottom_px']);
        self::assertArrayNotHasKey('margin_left_px', $normalized);
        self::assertSame(32, $normalized['padding_top_px']);
        self::assertArrayNotHasKey('padding_right_px', $normalized);
        self::assertSame(700, $normalized['font_weight']);
        self::assertArrayNotHasKey('line_height', $normalized, '9 超出 0.8–3');
        self::assertSame('center', $normalized['text_align']);
        self::assertSame(2, $normalized['border_width_px']);
        self::assertSame(12, $normalized['radius_px']);
        self::assertSame(50, $normalized['width_pct']);
        self::assertSame(720, $normalized['max_width_px']);
        self::assertSame('space-between', $normalized['justify_content']);
        self::assertSame('center', $normalized['align_items']);
        self::assertArrayNotHasKey('font_size_px', $normalized);

        $rejected = BloxGlobalClasses::normalizeSettings([
            'font_weight' => '650',
            'text_align' => 'start;color:red',
            'justify_content' => 'normal',
            'align_items' => ['center'],
            'line_height' => '1.5;x',
            'border_width_px' => 21,
            'radius_px' => 'full',
            'width_pct' => 0,
            'max_width_px' => 39,
        ]);
        self::assertSame([], $rejected);

        self::assertSame(1.46, BloxGlobalClasses::normalizeSettings(['line_height' => '1.456'])['line_height']);
    }

    public function testLegacySettingsKeepTheirShapeAndOutput(): void
    {
        BloxResponsiveValue::overrideWideEnabled(false);
        $legacy = [
            'text_color' => '#333333',
            'border_color' => '#dddddd',
            'radius' => 'md',
            'padding_px' => ['d' => 32, 'm' => 16],
            'gap_px' => 12,
            'font_size_px' => 18,
        ];
        self::assertEquals($legacy, BloxGlobalClasses::normalizeSettings($legacy)); // 键序可变，值与形状不变
        self::assertSame(
            '.yk-c-card:not(yk-none){color:#333333;border-color:#dddddd;border-radius:0.5rem;border-style:solid;border-width:1px;padding:16px;font-size:18px;gap:12px}'
            . '@media (min-width:768px){.yk-c-card:not(yk-none){padding:32px}}',
            BloxGlobalClasses::classRules('card', $legacy)
        );
    }

    public function testFirstBatchPropertiesCompileToWhitelistedDeclarations(): void
    {
        BloxResponsiveValue::overrideWideEnabled(false);
        $css = BloxGlobalClasses::classRules('hero-title', BloxGlobalClasses::normalizeSettings([
            'text_color' => 'var(--yk-color-primary)',
            'bg_color' => '#fff7ed',
            'font_size_px' => ['d' => 40, 't' => 32, 'm' => 28],
            'font_weight' => 800,
            'line_height' => 1.2,
            'text_align' => 'center',
            'margin_top_px' => 24,
            'margin_bottom_px' => ['d' => 32, 'm' => 16],
            'padding_top_px' => 8,
            'border_width_px' => 2,
            'radius_px' => 6,
        ]));

        self::assertSame(
            '.yk-c-hero-title:not(yk-none){color:var(--yk-color-primary);background-color:#fff7ed;border-radius:6px;border-style:solid;border-width:2px;font-weight:800;text-align:center;line-height:1.2;padding-top:8px;margin-top:24px;margin-bottom:16px;font-size:28px}'
            . '@media (min-width:768px){.yk-c-hero-title:not(yk-none){margin-bottom:32px;font-size:32px}}'
            . '@media (min-width:1024px){.yk-c-hero-title:not(yk-none){font-size:40px}}',
            $css
        );
    }

    public function testRadiusPxWinsOverLegacyRadiusPreset(): void
    {
        $css = BloxGlobalClasses::classRules('pill', ['radius' => 'lg', 'radius_px' => 20]);
        self::assertStringContainsString('border-radius:20px', $css);
        self::assertStringNotContainsString('0.75rem', $css);
    }

    public function testFlexAndSizingProperties(): void
    {
        BloxResponsiveValue::overrideWideEnabled(false);
        $css = BloxGlobalClasses::classRules('stack', [
            'justify_content' => 'space-between',
            'align_items' => 'center',
            'gap_px' => ['d' => 24, 'm' => 12],
            'width_pct' => ['d' => 50, 'm' => 100],
            'max_width_px' => 960,
        ]);
        self::assertSame(
            '.yk-c-stack:not(yk-none){justify-content:space-between;align-items:center;gap:12px;width:100%;max-width:960px}'
            . '@media (min-width:768px){.yk-c-stack:not(yk-none){gap:24px;width:50%}}',
            $css
        );
    }

    /** 简写 padding 在某档改变时，同档必须重申已设的分边值，否则分边被简写吃掉。 */
    public function testShorthandPaddingRestatesSidesInEveryRuleItAppears(): void
    {
        BloxResponsiveValue::overrideWideEnabled(false);
        $css = BloxGlobalClasses::classRules('box', [
            'padding_px' => ['d' => 32, 't' => 24, 'm' => 16],
            'padding_top_px' => 40,
        ]);
        self::assertSame(
            '.yk-c-box:not(yk-none){padding:16px;padding-top:40px}'
            . '@media (min-width:768px){.yk-c-box:not(yk-none){padding:24px;padding-top:40px}}'
            . '@media (min-width:1024px){.yk-c-box:not(yk-none){padding:32px;padding-top:40px}}',
            $css
        );
    }

    /** 没有桌面值、只设了单档的：区间规则，只作用于该档，不向桌面泄漏；排在移动优先规则之后。 */
    public function testTierOnlyValuesEmitRangeRules(): void
    {
        BloxResponsiveValue::overrideWideEnabled(true);
        $css = BloxGlobalClasses::classRules('mobile-tight', [
            'padding_top_px' => ['m' => 8],
            'margin_bottom_px' => ['t' => 20],
            'font_size_px' => ['w' => 48],
            'text_color' => '#111111',
        ]);
        self::assertSame(
            '.yk-c-mobile-tight:not(yk-none){color:#111111}'
            . '@media not all and (min-width:768px){.yk-c-mobile-tight:not(yk-none){padding-top:8px;margin-bottom:20px}}'
            . '@media (min-width:768px) and (max-width:1023.98px){.yk-c-mobile-tight:not(yk-none){margin-bottom:20px}}'
            . '@media (min-width:1440px){.yk-c-mobile-tight:not(yk-none){font-size:48px}}',
            $css
        );
        self::assertSame(['m' => 8], BloxGlobalClasses::normalizeSettings(['padding_top_px' => ['m' => 8]])['padding_top_px']);
    }

    public function testWideTierFollowsTheSiteToggle(): void
    {
        $settings = ['margin_top_px' => ['d' => 24, 'w' => 48], 'font_size_px' => ['w' => 60]];

        BloxResponsiveValue::overrideWideEnabled(true);
        $on = BloxGlobalClasses::classRules('wide', $settings);
        self::assertStringContainsString('@media (min-width:1440px){.yk-c-wide:not(yk-none){margin-top:48px}}', $on);
        self::assertStringContainsString('font-size:60px', $on);

        BloxResponsiveValue::overrideWideEnabled(false);
        $off = BloxGlobalClasses::classRules('wide', $settings);
        self::assertSame('.yk-c-wide:not(yk-none){margin-top:24px}', $off);
    }

    /**
     * 多类冲突：样式表按类名升序输出，同一属性后者胜出——与元素上挂载的顺序无关。
     * 编辑器冲突提示（BloxStyleSources.classConflicts）按同一规则判定。
     */
    public function testStylesheetOrdersClassesByNameSoLaterNamesWin(): void
    {
        $zeta = BloxGlobalClasses::mutate('class_add', ['name' => 'zeta-color', 'settings' => ['text_color' => '#222222']], true);
        $alpha = BloxGlobalClasses::mutate('class_add', ['name' => 'alpha-color', 'settings' => ['text_color' => '#111111']], true);
        BloxGlobalClasses::resetForTests();

        $css = BloxGlobalClasses::stylesheet();
        self::assertLessThan(strpos($css, '.yk-c-zeta-color'), strpos($css, '.yk-c-alpha-color'));
        // 挂载顺序 zeta, alpha：class 属性按挂载顺序，胜负仍由样式表顺序决定
        self::assertSame(' yk-c-zeta-color yk-c-alpha-color', BloxGlobalClasses::classAttributeFor([
            '_classes' => [$zeta['class_id'], $alpha['class_id']],
        ]));
    }
}
