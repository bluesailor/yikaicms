<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php'; // BloxIcon + 元素/资产收集器（单跑本文件也可用）

final class BloxIconTest extends TestCase
{
    public function testLegacyValueRemainsTabler(): void
    {
        $this->assertSame('home', BloxIcon::normalize('home'));
        $this->assertSame('ti ti-home', BloxIcon::classes('home'));
        $this->assertNull(BloxIcon::stylesheet('home'));
    }

    public function testTablerIconOutsideSiteSubsetLoadsFullFallback(): void
    {
        BloxAssetCollector::reset();

        $this->assertSame('ti ti-abacus', BloxIcon::classes('abacus'));
        $this->assertSame(BloxIcon::TABLER_STYLESHEET, BloxIcon::stylesheet('abacus'));
        $this->assertContains(BloxIcon::TABLER_STYLESHEET, BloxAssetCollector::styles());
    }

    public function testBootstrapValueKeepsNamespace(): void
    {
        $this->assertSame('bi:house-door', BloxIcon::normalize('BI:House-Door'));
        $this->assertSame('bi bi-house-door', BloxIcon::classes('bi:house-door'));
        $this->assertSame(
            '/assets/bootstrap-icons/bootstrap-icons.min.css',
            BloxIcon::stylesheet('bi:house-door')
        );
    }

    public function testUnsupportedNamespaceAndUnsafeValueFallBack(): void
    {
        $this->assertSame('star', BloxIcon::normalize('fa:house'));
        $this->assertSame('ti ti-script', BloxIcon::classes('"><script>'));
        $this->assertSame('ti ti-chart-bar', BloxIcon::classes(null, 'chart-bar'));
    }

    public function testNoneIsOnlyTheLegacySentinel(): void
    {
        $this->assertTrue(BloxIcon::isNone('none'));
        $this->assertNull(BloxIcon::stylesheet('none'));
        $this->assertFalse(BloxIcon::isNone('bi:none'));
    }

    public function testBusinessPresetsUseSafeIconsAndWhitelistedMotion(): void
    {
        $presets = BloxIcon::businessPresets();

        $this->assertCount(12, $presets);
        $this->assertSame('headset', $presets[0]['icon']);
        $this->assertSame('ring', $presets[0]['motion']);
        foreach ($presets as $preset) {
            $this->assertSame($preset['icon'], BloxIcon::normalize($preset['icon']));
            $this->assertNotSame('', $preset['label']);
            $this->assertSame(
                ' yk-icon-motion yk-icon-motion--' . $preset['motion'],
                BloxIcon::motionClass($preset['motion'])
            );
        }

        $this->assertSame('', BloxIcon::motionClass('bounce'));
        $this->assertSame('', BloxIcon::motionClass('none'));
        $this->assertSame('', BloxIcon::motionClass('\"><script>'));
        $this->assertSame(
            ['none', 'pulse', 'ring', 'slide', 'spin', 'sparkle', 'lift'],
            array_keys(BloxIcon::motionOptions())
        );
    }

    public function testElementRenderersUseBootstrapClassesAndAsset(): void
    {
        BloxAssetCollector::reset();
        $icon = BuilderRegistry::get('icon');
        $this->assertNotNull($icon);
        $html = BlockRenderer::renderElementNode([
            'type' => 'icon',
            'data' => ['icon' => 'bi:house-door', 'size' => 'md'],
        ]);

        $this->assertStringContainsString('class="bi bi-house-door inline-block"', $html);
        $this->assertContains('/assets/bootstrap-icons/bootstrap-icons.min.css', BloxAssetCollector::styles());
    }

    public function testSubsetBusinessIconDoesNotQueueFullFont(): void
    {
        BloxAssetCollector::reset();
        $icon = BuilderRegistry::get('icon');
        $this->assertNotNull($icon);

        $html = BlockRenderer::renderElementNode([
            'type' => 'icon',
            'data' => ['icon' => 'headset', 'size' => 'md'],
        ]);

        $this->assertStringContainsString('class="ti ti-headset inline-block"', $html);
        $this->assertNotContains(BloxIcon::TABLER_STYLESHEET, BloxAssetCollector::styles());
    }

    public function testIconBoxRendersWhitelistedHoverMotionOnly(): void
    {
        $html = BlockRenderer::renderElementNode([
            'type' => 'icon-box',
            'data' => ['icon' => 'headset', 'icon_motion' => 'ring', 'title' => 'Service'],
        ]);
        $this->assertStringContainsString('yk-icon-interactive', $html);
        $this->assertStringContainsString('yk-icon-motion--ring', $html);

        $unsafe = BlockRenderer::renderElementNode([
            'type' => 'icon-box',
            'data' => ['icon' => 'headset', 'icon_motion' => '\"><script>', 'title' => 'Service'],
        ]);
        $this->assertStringNotContainsString('yk-icon-motion--', $unsafe);
        $this->assertStringNotContainsString('<script>', $unsafe);
    }
    /** 2.0.4 插件 / 主题注册图标集：前缀解析、类名模板、按需加载样式表；停用后回落默认图标。 */
    public function testRegisteredIconSetsParseRenderAndLoadTheirStylesheet(): void
    {
        BloxIcon::resetSetsForTests();
        BloxAssetCollector::reset();
        try {
            self::assertTrue(BloxIcon::registerSet('lucide', [
                'label' => 'Lucide', 'stylesheet' => BloxIcon::BOOTSTRAP_STYLESHEET, 'class' => 'lucide lucide-{name}',
                'icons' => ['rocket', 'Anchor', 'bad name', 'rocket'],
            ]));
            self::assertSame('lucide:rocket', BloxIcon::normalize('Lucide:Rocket'));
            self::assertSame('lucide lucide-rocket', BloxIcon::classes('lucide:rocket'));
            self::assertContains(BloxIcon::BOOTSTRAP_STYLESHEET, BloxAssetCollector::styles());
            self::assertSame(BloxIcon::BOOTSTRAP_STYLESHEET, BloxIcon::stylesheet('lucide:rocket'));
            self::assertSame(['rocket', 'anchor'], BloxIcon::editorSets()[0]['icons'], '图标名去重、小写、过滤非法');
            self::assertSame('star', BloxIcon::normalize('lucide:<script>'));

            // 不合法的注册：占用内置前缀、外部或带 .. 的样式表、类名模板缺 {name} 或带引号
            self::assertFalse(BloxIcon::registerSet('bi', ['stylesheet' => '/assets/x.css', 'class' => 'x-{name}']));
            self::assertFalse(BloxIcon::registerSet('ext', ['stylesheet' => 'https://cdn.example/x.css', 'class' => 'x-{name}']));
            self::assertFalse(BloxIcon::registerSet('ext', ['stylesheet' => '//cdn.example/x.css', 'class' => 'x-{name}']));
            self::assertFalse(BloxIcon::registerSet('ext', ['stylesheet' => '/plugins/../config/x.css', 'class' => 'x-{name}']));
            self::assertFalse(BloxIcon::registerSet('ext', ['stylesheet' => '/x.css', 'class' => 'x-icon']));
            self::assertFalse(BloxIcon::registerSet('ext', ['stylesheet' => '/assets/x.css', 'class' => 'x" onclick="{name}']));
            self::assertFalse(BloxIcon::registerSet('ext', ['stylesheet' => '/config/x.css', 'class' => 'x-{name}']), '只收 assets / plugins / 主题 assets');

            // 过滤器注册同样生效；与直接注册同前缀时直接注册优先
            if (!function_exists('add_filter')) {
                require_once ROOT_PATH . '/includes/hooks.php';
            }
            add_filter('blox_icon_sets', static fn (array $sets): array => $sets + [
                'phosphor' => ['label' => 'Phosphor', 'stylesheet' => '/themes/default/assets/phosphor.css', 'class' => 'ph ph-{name}'],
                'lucide' => ['stylesheet' => '/other.css', 'class' => 'other-{name}'],
            ]);
            self::assertSame('ph ph-house', BloxIcon::classes('phosphor:house'));
            self::assertSame('lucide lucide-rocket', BloxIcon::classes('lucide:rocket'));
        } finally {
            unset($GLOBALS['ik_filters']['blox_icon_sets']);
            BloxIcon::resetSetsForTests();
        }
        self::assertSame('star', BloxIcon::normalize('lucide:rocket'), '插件停用后回落默认图标');
    }
}
