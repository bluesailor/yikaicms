<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 长内容折叠（容器 / Div，2.0.3，免费核心功能）：按设备的折叠高度、透明或颜色渐隐、按钮样式与位置、动画。
 * 结构：根（背景、内边距、圆角、在父级中的布局）> 内层 .yk-collapse-body（子项布局 + 裁切）+ 按钮；内容始终完整输出。
 */
final class BloxCollapseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function setUp(): void
    {
        BloxAssetCollector::resetForTests();
    }

    public function testOffByDefaultAndExistingOutputIsUnchanged(): void
    {
        foreach ([new ContainerElement(), new DivElement()] as $element) {
            $data = ['padding' => 'md', 'gap' => 'lg', 'display' => 'flex'];
            $html = $element->render($data, '<p>x</p>');
            self::assertStringNotContainsString('yk-collapse', $html);
            self::assertStringNotContainsString('data-yk-collapse', $html);
            self::assertSame([], BloxAssetCollector::scripts());
            // 开关关着、只填了其它折叠设置，也不改变输出
            self::assertSame($html, $element->render($data + ['collapse_height' => '200', 'collapse_fade' => 'color'], '<p>x</p>'));
        }
        // 老布局的类顺序：Div 为 根 + 布局 + 盒子（取自 2.0.3 改动前 a8acdd1e 的实际输出）
        self::assertSame('<div class="yk-div flex flex-col gap-0 p-6"><p>x</p></div>',
            (new DivElement())->render(['display' => 'flex', 'padding' => 'md'], '<p>x</p>'));
    }

    public function testControlsLiveInTheirOwnStyleGroup(): void
    {
        foreach ([new ContainerElement(), new DivElement()] as $element) {
            $controls = array_column($element->controls(), null, 'key');
            foreach (['collapse', 'collapse_height', 'collapse_fade', 'collapse_fade_color', 'collapse_more_text', 'collapse_less_text',
                'collapse_button_style', 'collapse_button_align', 'collapse_icon', 'collapse_animate'] as $key) {
                self::assertArrayHasKey($key, $controls, $key);
                self::assertSame('collapse', $controls[$key]['group'], $key);
                self::assertSame('style', $controls[$key]['tab'], $key);
            }
            self::assertFalse($controls['collapse']['default']);
            self::assertTrue($controls['collapse_height']['responsive']);
            self::assertArrayHasKey('', $controls['collapse_height']['options'], 'each device can opt out');
        }
        self::assertStringContainsString('"collapse"', (string) file_get_contents(ROOT_PATH . '/assets/js/blox-style-groups.js'));
    }

    public function testContainerSplitsIntoRootAndClippedBody(): void
    {
        $html = (new ContainerElement())->render([
            'collapse' => true, 'collapse_height' => ['d' => '400', 'm' => ''], 'padding' => 'md', 'gap' => 'lg', 'bg_color' => '#f5f5f5',
        ], '<p>long</p>');

        self::assertMatchesRegularExpression('/^<div class="yk-container yk-collapse p-6[^"]*" style="[^"]*--ykc-h-m:none;--ykc-h-t:400px;--ykc-h-d:400px;--ykc-h-w:400px" data-yk-collapse data-yk-collapse-state="collapsed" data-yk-collapse-fade="mask" data-yk-collapse-animate>/', $html);
        preg_match('/<div class="yk-collapse-body ([^"]*)" id="(ykc-[0-9a-f]{8}-\d+)"/', $html, $body);
        self::assertNotEmpty($body, 'inner body carries the layout and an id');
        self::assertStringContainsString('flex', $body[1]);
        self::assertStringNotContainsString('p-6', $body[1], 'padding stays on the root so the button sits inside it');
        self::assertStringContainsString('aria-controls="' . $body[2] . '"', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('<p>long</p>', $html, 'full content is always in the HTML');
        self::assertStringContainsString('data-yk-collapse-toggle hidden', $html, 'button stays hidden until the script finds real overflow');
        self::assertContains('/assets/js/blox-collapse.js', BloxAssetCollector::scripts());
        self::assertContains('/assets/css/blox-collapse.css', BloxAssetCollector::styles());
    }

    public function testNoHeightOnAnyDeviceMeansNoCollapse(): void
    {
        $html = (new ContainerElement())->render(['collapse' => true, 'collapse_height' => ''], '<p>x</p>');
        self::assertStringNotContainsString('yk-collapse', $html);
    }

    public function testFadeButtonTextStyleAlignmentAndEscaping(): void
    {
        $element = new DivElement();
        $html = $element->render([
            'collapse' => true, 'collapse_fade' => 'color', 'collapse_fade_color' => '#112233',
            'collapse_more_text' => '<b>更多</b>', 'collapse_less_text' => '收',
            'collapse_button_style' => 'solid', 'collapse_button_align' => 'right', 'collapse_icon' => false, 'collapse_animate' => false,
        ], '<p>x</p>');
        self::assertStringContainsString('--ykc-fade:#112233', $html);
        self::assertStringContainsString('data-yk-collapse-fade="color"', $html);
        self::assertStringNotContainsString('data-yk-collapse-animate', $html);
        self::assertStringContainsString('&lt;b&gt;更多&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>更多', $html);
        self::assertStringContainsString('data-yk-collapse-less="收"', $html);
        self::assertStringContainsString('justify-end', $html);
        self::assertStringContainsString('bg-primary', $html);
        self::assertStringNotContainsString('ti-chevron-down', $html);

        $bad = $element->render(['collapse' => true, 'collapse_fade' => 'color', 'collapse_fade_color' => 'red;x:url(y)'], '');
        self::assertStringContainsString('--ykc-fade:#ffffff', $bad, 'invalid colours fall back to white');
        $defaults = $element->render(['collapse' => true], '');
        self::assertStringContainsString('data-yk-collapse-more="' . __('blox_collapse_more_default') . '"', $defaults);
        self::assertStringContainsString('ti-chevron-down', $defaults);
    }

    public function testVideoBackgroundAndOverlayDiv(): void
    {
        $video = (new ContainerElement())->render(['collapse' => true, 'bg_video' => '/uploads/a.mp4', 'padding' => 'sm'], '<p>x</p>');
        self::assertStringContainsString('class="blox-content yk-collapse-body', $video);
        self::assertStringContainsString('yk-container blox-has-bg yk-collapse', $video);
        self::assertStringContainsString('data-yk-collapse-toggle', $video);

        $overlay = (new DivElement())->render(['collapse' => true, 'display' => 'overlay'], '<p>x</p>');
        self::assertStringNotContainsString('yk-collapse', $overlay, 'stacked overlay divs have nothing to collapse');
    }

    public function testNoscriptFallbackAndAssetsShip(): void
    {
        $page = (new DivElement())->render(['collapse' => true], 'a') . (new DivElement())->render(['collapse' => true], 'b');
        self::assertLessThanOrEqual(1, substr_count($page, '<noscript>'), 'fallback style is emitted at most once per request');
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/blox-collapse.css');
        foreach (['--ykc-h-m', '--ykc-h-t', '--ykc-h-d', '--ykc-h-w', 'gap: inherit', 'mask-image', '@media print', 'prefers-reduced-motion'] as $needle) {
            self::assertStringContainsString($needle, $css, $needle);
        }
        $js = (string) file_get_contents(ROOT_PATH . '/assets/js/blox-collapse.js');
        foreach (['aria-expanded', 'tabindex', 'ResizeObserver', 'prefers-reduced-motion'] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }
        $manifest = json_decode((string) file_get_contents(ROOT_PATH . '/config/blox-assets.json'), true);
        self::assertContains('assets/css/blox-collapse.css', $manifest['runtime']);
        self::assertContains('assets/js/blox-collapse.js', $manifest['runtime']);
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach (['blox_style_group_collapse', 'blox_collapse_enable', 'blox_collapse_height', 'blox_collapse_more_default', 'blox_collapse_less_default'] as $key) {
                self::assertNotSame('', trim((string) ($strings[$key] ?? '')), "$lang $key");
            }
        }
    }
}
