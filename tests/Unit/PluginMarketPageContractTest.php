<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PluginMarketPageContractTest extends TestCase
{
    public function testDeepLinkSearchFiltersBySlugWithoutUpdateCheckOverwrite(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/plugin.php');
        $checkStart = strpos($page, 'async checkUpdates()');
        $searchStart = strpos($page, 'async search()');

        self::assertNotFalse($checkStart);
        self::assertNotFalse($searchStart);
        self::assertStringContainsString("ykQs.get('q')", $page);
        self::assertStringContainsString('filterMarketItems(items)', $page);
        self::assertStringContainsString('[p.slug, p.name, p.description, p.category, p.author]', $page);
        self::assertStringContainsString('this.items = this.filterMarketItems(', $page);

        $updateCheck = substr($page, (int) $checkStart, (int) $searchStart - (int) $checkStart);
        self::assertStringContainsString('var marketItems =', $updateCheck);
        self::assertStringNotContainsString('this.items =', $updateCheck);
        self::assertStringNotContainsString('this.loaded =', $updateCheck);
    }

    public function testMarketActionLabelsDoNotBreakAlpineAttributes(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/plugin.php');

        self::assertStringContainsString('marketText:', $page);
        self::assertStringContainsString('marketText.installing : marketText.install', $page);
        self::assertStringContainsString('marketText.upgrading : marketText.upgrade', $page);
        self::assertStringNotContainsString('x-text="installing === p.slug ? <?php', $page);
        self::assertStringNotContainsString("? <?php echo json_encode(__('pl_upgrading')", $page);
    }

    public function testMarketChecksOriginBeforeDownloadAndPassesIdentityIntoInstaller(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/plugin.php');
        // 市场安装链在 PluginMarketInstall（插件页与整站模板共用）；插件页只调用它
        $market = (string) file_get_contents(ROOT_PATH . '/includes/PluginMarketInstall.php');
        $case = substr($page, (int) strpos($page, "case 'market_install':"));
        self::assertStringContainsString('(new PluginMarketInstall(ROOT_PATH))->install($slug)', $case);
        $check = strpos($market, '->assertOrigin($slug, $origin)');
        $download = strpos($market, '$download = ($this->httpGet)(');
        self::assertIsInt($check);
        self::assertIsInt($download);
        self::assertLessThan($download, $check);
        self::assertStringContainsString('$this->installZip($tmpZip, $slug, $marketVersion, $origin)', $market);
        self::assertStringNotContainsString('deletePluginDir(', $market);
        self::assertStringNotContainsString('deletePluginDir($pluginSlug)', $case);
        self::assertStringNotContainsString('$zip->extractTo($pluginsDir)', $page);
        self::assertStringContainsString("'status' => 0", $market);
        self::assertStringContainsString('pluginMarketDecorate', $page);
    }

    public function testBuiltInPluginsShowBadgeAndLinkInsteadOfInstall(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/plugin.php');
        $decorate = substr($page, (int) strpos($page, 'function pluginMarketDecorate('), 2000);
        // 已内置判定在来源检查之前（老站残留的旧插件目录没有安装回执，来源检查会误报）
        $builtIn = strpos($decorate, 'MarketBuiltInPlugins::matches($item, retiredPlugins())');
        self::assertIsInt($builtIn);
        self::assertLessThan((int) strpos($decorate, '->assertOrigin('), $builtIn);
        // 地址带子目录前缀，且只给能进内置页面的账号
        self::assertStringContainsString("hasPermission('*') ? BasePath::url(\$target) : null", $decorate);

        $status = substr($page, (int) strpos($page, 'statusOf(p) {'), 400);
        self::assertLessThan((int) strpos($status, 'p.slug in this.local'), (int) strpos($status, "if (p.built_in) return 'builtin';"));
        self::assertStringContainsString("x-if=\"statusOf(p) === 'builtin'\"", $page);
        self::assertStringContainsString(':href="p.built_in_url"', $page);
        self::assertStringContainsString("__('pl_market_built_in')", $page);
        // 已安装插件的「可升级」检测不把内置条目算进去
        self::assertStringContainsString('if (!it.built_in && it.slug in this.local', $page);
    }
}
