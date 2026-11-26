<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use MarketBuiltInPlugins;
use MarketDownloadStatus;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketBuiltInPlugins.php';
require_once ROOT_PATH . '/includes/MarketDownloadStatus.php';

/** 2.3.1：已并入主程序的插件在市场里显示「已内置」，不再给安装或升级。 */
final class MarketBuiltInPluginsTest extends TestCase
{
    /** 线上目录里 product-import 1.0.2 的原样条目（字段与 data/plugins.json 一致，签名截短） */
    private const ENTRY = [
        'slug' => 'product-import', 'name' => '产品导入', 'version' => '1.0.2', 'author' => 'Yikai CMS',
        'requires_php' => '8.0', 'requires_cms' => '1.18.5', 'category' => 'general',
        'package' => 'product-import-v1.0.2.zip', 'size_kb' => 17,
        'hash' => 'sha256:122abfe4b8e94696ef5bc36fb1b409c874ebfc34ebf2d68333816560a6e0ce31',
        'sig' => 'pwfhfFNmp2oB', 'icon' => 'ti-file-import',
        'download_url' => 'https://down.yikai.cn/soft/yikaicms/plugins/product-import-v1.0.2.zip',
    ];

    public function testRetiredEntryBecomesBuiltInWithLinkAndNoDownloadDetails(): void
    {
        $item = MarketBuiltInPlugins::decorate(self::ENTRY, ['product-import'], '/sub/admin/product_import.php');

        self::assertTrue($item['built_in']);
        self::assertSame('/sub/admin/product_import.php', $item['built_in_url']);
        self::assertSame('', $item['download_url']);
        foreach (['package', 'hash', 'sig', 'size_kb', 'locked_reason'] as $key) {
            self::assertArrayNotHasKey($key, $item, $key);
        }
        // 名称、版本、说明照常显示
        self::assertSame(['产品导入', '1.0.2', 'Yikai CMS'], [$item['name'], $item['version'], $item['author']]);
        // 不显示下载受限的提示（不是「下载不了」，是「不需要下载」）
        $decorated = MarketDownloadStatus::decorate($item);
        self::assertFalse($decorated['download_blocked']);
        self::assertSame('', $decorated['download_message']);
    }

    public function testNoLinkWhenTheAccountCannotOpenTheBuiltInPage(): void
    {
        $item = MarketBuiltInPlugins::decorate(self::ENTRY, ['product-import'], null);
        self::assertTrue($item['built_in']);
        self::assertSame('', $item['built_in_url']);
    }

    public function testServerLockReasonDoesNotTurnBuiltInIntoARestriction(): void
    {
        $item = MarketBuiltInPlugins::decorate(self::ENTRY + ['locked_reason' => 'origin_unknown'], ['product-import'], '/admin/product_import.php');
        self::assertArrayNotHasKey('locked_reason', $item);
        self::assertFalse(MarketDownloadStatus::decorate($item)['download_blocked']);
    }

    public function testOtherPluginsAndOlderCoresAreUntouched(): void
    {
        $seo = ['slug' => 'seo', 'version' => '1.3.3', 'download_url' => 'https://down.yikai.cn/soft/yikaicms/plugins/seo-v1.3.3.zip', 'hash' => 'sha256:x'];
        self::assertSame($seo, MarketBuiltInPlugins::decorate($seo, ['product-import'], '/admin/product_import.php'));
        // 没有内置它的版本（retiredPlugins() 不含它）原样保留，照常能装
        self::assertSame(self::ENTRY, MarketBuiltInPlugins::decorate(self::ENTRY, [], '/admin/product_import.php'));
        self::assertFalse(MarketBuiltInPlugins::matches(['slug' => 7], ['product-import']));
        self::assertFalse(MarketBuiltInPlugins::matches([], ['product-import']));
    }

    public function testCoreListsProductImportAsBuiltInWithItsAdminPage(): void
    {
        require_once ROOT_PATH . '/includes/plugin.php';
        self::assertContains('product-import', retiredPlugins());
        self::assertSame('/admin/product_import.php', retiredPluginRedirect('product-import'));
        self::assertNull(retiredPluginRedirect('seo'));
        self::assertFileExists(ROOT_PATH . '/admin/product_import.php');
    }

    /** 子目录站：旧地址 plugin_page.php?plugin=product-import 只补一次前缀（2.3.0 起曾跳到 /sub/sub/admin/…） */
    public function testRetiredPluginRedirectGetsTheSubdirectoryPrefixOnce(): void
    {
        require_once ROOT_PATH . '/includes/BasePath.php';
        require_once ROOT_PATH . '/includes/plugin.php';
        $page = (string) file_get_contents(ROOT_PATH . '/admin/plugin_page.php');
        self::assertStringContainsString("header('Location: ' . \$retiredTarget", $page);
        self::assertSame('/sub/admin/product_import.php', \BasePath::prefixLocation((string) retiredPluginRedirect('product-import'), '/sub'));

        // 发头回调已统一补前缀：任何 Location 头都不能再先套 BasePath::url()
        $offenders = [];
        foreach (['admin', 'includes', 'member', 'api'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') continue;
                if (preg_match("/(?:Location:\\s*'\\s*\\.\\s*|redirect\\(\\s*)BasePath::url\\(/", (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen(ROOT_PATH) + 1);
                }
            }
        }
        self::assertSame([], $offenders);
    }
}
