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
        $market = substr($page, (int) strpos($page, "case 'market_install':"));
        $check = strpos($market, '->assertOrigin($slug, $origin)');
        $download = strpos($market, '$body = pluginMarketHttpGet(');
        self::assertIsInt($check);
        self::assertIsInt($download);
        self::assertLessThan($download, $check);
        self::assertStringContainsString("pluginInstallFromZip(\$tmpZip, \$slug, (string) (\$item['version'] ?? ''), \$origin)", $market);
        self::assertStringNotContainsString('deletePluginDir($pluginSlug)', $market);
        self::assertStringNotContainsString('$zip->extractTo($pluginsDir)', $page);
        self::assertStringContainsString("'status' => 0", $page);
        self::assertStringContainsString('pluginMarketDecorate', $page);
    }
}
