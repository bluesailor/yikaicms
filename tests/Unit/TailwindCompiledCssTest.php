<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tailwind v4 独立编译器会跳过 .gitignore 的源文件；Blox 编辑器现已由主仓跟踪，
 * app.css 仍用显式 @source 锁定扫描范围，避免编译入口调整后漏掉编辑器工具类。
 * 2026-08-20 事故：min-h-0/pb-16 从未被编译，元素库高度约束链断裂、从不滚动，
 * 1.18.1/1.18.2 均带病发布。本测试锁住编辑器布局关键类必须存在于编译产物。
 */
final class TailwindCompiledCssTest extends TestCase
{
    public function testEditorCriticalUtilitiesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        // 元素库/设置面板滚动布局的生命线：flex 高度约束 + 底部留白。
        foreach (['.min-h-0{', '.pb-24{'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $css,
                "编译产物缺少 {$selector}——大概率 app.css 的 blox_editor @source 豁免被移除，" .
                '或编译时源文件是旧版。重跑 Tailwind CSS 编译并核对 AGENTS.md 的构建约定。'
            );
        }

        // 豁免声明本身也不许丢。
        $appCss = file_get_contents(ROOT_PATH . '/assets/css/src/app.css');
        self::assertNotFalse($appCss);
        self::assertStringContainsString('admin/blox_editor/**/*.php', $appCss);
    }

    /** 区域预设卡片线框示意图（blox_templates.php）的关键类必须在产物里。 */
    public function testAreaPresetPreviewUtilitiesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        foreach (['.xl\:grid-cols-4{', '.text-\[8px\]{', '.rounded-t-sm{', '.w-4\/5{'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $css,
                "编译产物缺少 {$selector}——改动 admin/blox_templates.php 线框类后需重跑 tailwindcss 编译。"
            );
        }
    }

    /** 样式分组 chip 与有值圆点（style-groups.php，2026-09-02 第 2 轮）的关键类必须在产物里。 */
    public function testStyleGroupDotUtilitiesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        foreach (['.w-1\.5{', '.h-1\.5{'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $css,
                "编译产物缺少 {$selector}——改动样式分组圆点类后需重跑 bash tools/build_css.sh。"
            );
        }
    }

    /** CTA 遮罩档位类（CtaElement::OVERLAY_MAP，2026-09-02 第 4 轮）必须在产物里。 */
    public function testCtaOverlayTierUtilitiesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        foreach (['.bg-black\/40', '.bg-black\/60', '.bg-black\/80'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $css,
                "编译产物缺少 {$selector}——改动 CTA 遮罩档位映射后需重跑 bash tools/build_css.sh。"
            );
        }
    }

    /** 背景视频三层结构（app.css，2026-09-02 第 5 轮）：定位与点击穿透契约必须在产物里。 */
    public function testBackgroundVideoLayerCssIsCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        self::assertStringContainsString('.blox-has-bg{', $css);
        self::assertStringContainsString('.blox-content{', $css);
        self::assertMatchesRegularExpression(
            '/\.blox-bg-media,\.blox-bg-overlay\{[^}]*pointer-events:none/',
            $css,
            '背景媒体层必须 pointer-events:none——编辑器画布选中/拖拽依赖此约束。'
        );
        self::assertMatchesRegularExpression(
            '/\.blox-bg-media video\{[^}]*object-fit:cover/',
            $css
        );
    }

    public function testBloxScrollAreasUseTailwind43Utilities(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);
        self::assertStringContainsString('.blox-scroll{', $css);
        self::assertStringContainsString('scrollbar-width:thin', $css);
        self::assertStringContainsString('scrollbar-gutter:stable', $css);
        self::assertStringContainsString('--tw-scrollbar-thumb:#cbd5e1', $css);
        self::assertStringContainsString(
            'scrollbar-color:var(--tw-scrollbar-thumb) var(--tw-scrollbar-track)',
            $css
        );
    }

    public function testBloxTemplatePanelContainerQueriesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);
        self::assertStringContainsString('container:blox-template-panel/inline-size', $css);
        self::assertStringContainsString('.blox-template-section-grid{', $css);
        self::assertStringContainsString('@container blox-template-panel (min-width:35rem)', $css);
        self::assertStringContainsString('@container blox-template-panel (min-width:64rem)', $css);
        self::assertStringContainsString('@container blox-template-panel (max-width:27.5rem)', $css);
    }

    public function testBloxPropertyPanelContainerQueriesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);
        self::assertStringContainsString('container:blox-property-panel/inline-size', $css);
        self::assertStringContainsString('.blox-property-pair-grid{', $css);
        self::assertStringContainsString('.blox-property-span-full{', $css);
        self::assertStringContainsString('@container blox-property-panel (min-width:24rem)', $css);
    }

    public function testBuildContractUsesCanonicalInputAndPinnedVersion(): void
    {
        self::assertFileDoesNotExist(ROOT_PATH . '/assets/css/input.css');
        self::assertFileExists(ROOT_PATH . '/assets/css/src/app.css');

        $package = json_decode((string) file_get_contents(ROOT_PATH . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame('4.3.3', $package['dependencies']['tailwindcss'] ?? null);
        self::assertSame('4.3.3', $package['dependencies']['@tailwindcss/cli'] ?? null);
        self::assertStringContainsString(
            '-i assets/css/src/app.css -o assets/css/tailwind.css',
            (string) ($package['scripts']['css'] ?? '')
        );
        self::assertStringNotContainsString(
            'assets/css/input.css',
            implode("\n", array_map('strval', $package['scripts'] ?? []))
        );

        $script = file_get_contents(ROOT_PATH . '/tools/build_css.sh');
        self::assertNotFalse($script);
        self::assertStringContainsString('EXPECTED_VERSION="4.3.3"', $script);
        self::assertStringContainsString('INPUT="assets/css/src/app.css"', $script);
        self::assertStringNotContainsString('assets/css/input.css', $script);

        $compiled = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($compiled);
        self::assertStringStartsWith('/*! tailwindcss v4.3.3 ', $compiled);
    }
}
