<?php
/**
 * 插件图标（2.0.3）：已安装与市场卡片共用的取值规则，以及图标都在自托管的 Tabler 字体里。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/PluginIcons.php';

final class PluginIconsTest extends TestCase
{
    public function testInstalledPluginsUseDeclaredIconThenOfficialTableThenDefault(): void
    {
        self::assertSame('ti-truck', PluginIcons::forInstalled('my-delivery', ['icon' => 'ti-truck']));
        self::assertSame('ti-shopping-bag', PluginIcons::forInstalled('shop', []));
        self::assertSame('ti-puzzle', PluginIcons::forInstalled('unknown-plugin', []));
        // 非法值（脚本注入、外部地址、别的字体前缀）一律不用
        foreach (['ti-x" onmouseover="alert(1)', 'https://evil.example/i.svg', 'fa-truck', 'ti-', 7] as $bad) {
            self::assertSame('ti-shopping-bag', PluginIcons::forInstalled('shop', ['icon' => $bad]));
        }
    }

    public function testMarketUsesCatalogIconsForOfficialEntriesOnly(): void
    {
        self::assertSame('ti-cookie', PluginIcons::forMarket(['slug' => 'cookie-consent', 'source' => 'official', 'icon' => 'ti-cookie']));
        self::assertSame('ti-cookie', PluginIcons::forMarket(['slug' => 'cookie-consent']), 'older catalogs without icon fall back to the table');
        self::assertSame('ti-puzzle', PluginIcons::forMarket(['slug' => 'shop', 'source' => 'community', 'icon' => 'ti-shopping-bag']));
        self::assertSame('ti-puzzle', PluginIcons::forMarket(['slug' => 'brand-new', 'source' => 'official']));
    }

    public function testEveryOfficialIconExistsInTheBundledFont(): void
    {
        $css = (string) file_get_contents(ROOT_PATH . '/assets/tabler/tabler-icons.min.css');
        $table = (new ReflectionClassConstant(PluginIcons::class, 'OFFICIAL'))->getValue();
        foreach (array_merge(array_values($table), [PluginIcons::DEFAULT]) as $icon) {
            self::assertStringContainsString('.' . $icon . ':before', $css, $icon);
        }
    }

    public function testBothPluginListsUseTheSharedRule(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/plugin.php');
        self::assertStringContainsString('PluginIcons::forMarket($item)', $page);
        self::assertStringContainsString('PluginIcons::forInstalled((string) $slug, $p)', $page);
        self::assertStringNotContainsString('ti-clipboard text-xl', $page);
    }
}
