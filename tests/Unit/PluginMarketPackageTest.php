<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketDownloadUrl.php';
require_once ROOT_PATH . '/includes/PluginMarketPackage.php';

/** 评审 P2-08 / P2-09：插件市场下载前只认官方地址、限制包体积、拒绝降级。 */
final class PluginMarketPackageTest extends TestCase
{
    public function testOnlyOfficialPackagePathsOrMarketTokensAreDownloaded(): void
    {
        self::assertTrue(PluginMarketPackage::isOfficialUrl('https://update.yikaicms.com/packages/plugins/seo-v1.2.0.zip', 'seo', '1.2.0'));
        self::assertTrue(PluginMarketPackage::isOfficialUrl(MarketDownloadUrl::ENDPOINT . '?token=abc_DEF-1.sig_2', 'seo', '1.2.0'));

        foreach ([
            'http://update.yikaicms.com/packages/plugins/seo-v1.2.0.zip',      // 非 HTTPS
            'https://evil.example/packages/plugins/seo-v1.2.0.zip',            // 外域
            'https://update.yikaicms.com.evil.example/packages/plugins/seo-v1.2.0.zip',
            'https://user@update.yikaicms.com/packages/plugins/seo-v1.2.0.zip', // userinfo
            'https://update.yikaicms.com:8443/packages/plugins/seo-v1.2.0.zip', // 端口
            'https://update.yikaicms.com/packages/plugins/seo-v1.1.0.zip',      // 版本不符
            'https://update.yikaicms.com/packages/plugins/stats-v1.2.0.zip',    // 插件不符
            'https://update.yikaicms.com/packages/themes/seo-v1.2.0.zip',       // 类型目录不符
            'https://update.yikaicms.com/packages/plugins/seo-v1.2.0.zip?x=1',  // 带查询
            'file:///etc/passwd',
        ] as $url) {
            self::assertFalse(PluginMarketPackage::isOfficialUrl($url, 'seo', '1.2.0'), $url);
        }
        self::assertFalse(PluginMarketPackage::isOfficialUrl('https://update.yikaicms.com/packages/plugins/..-v1.2.0.zip', '..', '1.2.0'));
    }

    public function testOlderMarketVersionIsRefusedButReinstallAndUpgradeAreAllowed(): void
    {
        self::assertTrue(PluginMarketPackage::isDowngrade('1.3.0', '1.2.9'));
        self::assertFalse(PluginMarketPackage::isDowngrade('1.3.0', '1.3.0'));
        self::assertFalse(PluginMarketPackage::isDowngrade('1.3.0', '1.10.0'));
        self::assertFalse(PluginMarketPackage::isDowngrade('', '1.0.0'), '未安装不算降级');
        self::assertFalse(PluginMarketPackage::isDowngrade('dev-main', '1.0.0'), '非数字版本不据此拒绝');
    }

    public function testInstalledVersionReadsTheLocalManifestOnly(): void
    {
        $root = sys_get_temp_dir() . '/yikai-plugin-market-' . bin2hex(random_bytes(4));
        mkdir($root . '/sample', 0700, true);
        file_put_contents($root . '/sample/plugin.json', '{"name":"Sample","version":" 2.1.0 "}');
        try {
            self::assertSame('2.1.0', PluginMarketPackage::installedVersion($root, 'sample'));
            self::assertSame('', PluginMarketPackage::installedVersion($root, 'missing'));
            self::assertSame('', PluginMarketPackage::installedVersion($root, '../sample'));
        } finally {
            unlink($root . '/sample/plugin.json');
            rmdir($root . '/sample');
            rmdir($root);
        }
    }

    public function testMarketInstallChecksUrlAndDowngradeBeforeDownloadingWithASizeCap(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/includes/PluginMarketInstall.php');
        $urlCheck = strpos($page, 'PluginMarketPackage::isOfficialUrl(');
        $downgrade = strpos($page, 'PluginMarketPackage::isDowngrade(');
        $download = strpos($page, '($this->httpGet)((string) $item[\'download_url\']');
        self::assertNotFalse($urlCheck);
        self::assertNotFalse($downgrade);
        self::assertNotFalse($download);
        self::assertLessThan($download, $urlCheck);
        self::assertLessThan($download, $downgrade);
        self::assertStringContainsString('120, PluginMarketPackage::MAX_PACKAGE_BYTES)', $page);
        self::assertStringNotContainsString('CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout', $page, '下载不再整包读入后才检查');
    }
}
