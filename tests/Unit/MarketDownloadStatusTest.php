<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketDownloadStatus.php';

final class MarketDownloadStatusTest extends TestCase
{
    public function testFreeQuotaLimitIsNotAPaidLicenseRequirement(): void
    {
        $item = MarketDownloadStatus::decorate([
            'paid' => false, 'locked_reason' => 'rate_limited', 'download_url' => 'must-not-be-used',
        ]);
        self::assertTrue($item['download_blocked']);
        self::assertFalse($item['paid']);
        self::assertSame('', $item['download_url']);
        self::assertSame('market_download_rate_limited', $item['download_message']);
    }

    public function testLegacyPaidAndUnknownRestrictionsStayBlocked(): void
    {
        self::assertSame('module_missing', MarketDownloadStatus::reason(['paid' => true, 'download_url' => '']));
        self::assertSame('plugin_locked_need_license', MarketDownloadStatus::message('module_missing'));
        $item = MarketDownloadStatus::decorate(['locked_reason' => '<script>new-reason</script>', 'download_url' => 'url']);
        self::assertTrue($item['download_blocked']);
        self::assertSame('market_download_unavailable', $item['download_message']);
        self::assertSame('', $item['download_url']);
        self::assertTrue(MarketDownloadStatus::decorate(['locked_reason' => ['bad']])['download_blocked']);
        self::assertFalse(MarketDownloadStatus::decorate(['paid' => false, 'download_url' => 'url'])['download_blocked']);
    }

    public function testHttpErrorsHaveRecoveryMessages(): void
    {
        foreach ([401 => 'market_download_refresh', 403 => 'market_download_refresh',
            410 => 'market_download_removed', 429 => 'market_download_rate_limited',
            503 => 'market_download_unavailable', 0 => 'market_download_unavailable'] as $status => $key) {
            self::assertSame($key, MarketDownloadStatus::httpMessage($status));
        }
    }

    public function testAdminGuardsRunBeforeDownloadsAndThemeLogOmitsToken(): void
    {
        $theme = (string) file_get_contents(ROOT_PATH . '/admin/theme.php');
        $plugin = (string) file_get_contents(ROOT_PATH . '/includes/PluginMarketInstall.php');
        self::assertLessThan(strpos($theme, 'ThemeMarket::downloadPackageToFile'), strpos($theme, "if (\$item && !empty(\$item['download_blocked']))"));
        self::assertLessThan(strpos($plugin, "(\$this->httpGet)((string) \$item['download_url']"), strpos($plugin, 'MarketDownloadStatus::reason($item)'));
        self::assertStringNotContainsString(". ' [' . \$download['code'] . '] ' . \$item['download_url']", $theme);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $plugin);
    }
}
