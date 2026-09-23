<?php
/**
 * 站点URL 与实际访问地址的比对（设置页提示与站点体检共用）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteAddress;

require_once ROOT_PATH . '/includes/SiteAddress.php';

final class SiteAddressTest extends TestCase
{
    /**
     * 2026-09-23 线上实例：装饰站制作时的本机地址跟着站点被拷上了 demo 域名。
     * 页面看起来一切正常，但 canonical、sitemap、分享卡片全部指向 127.0.0.1。
     */
    public function testLocalAddressCarriedOntoAPublicHostIsCaughtAsALeak(): void
    {
        $check = SiteAddress::inspect('http://127.0.0.1:8111', 'https://demo.yikaicms.com/yikai-zhuangshi.yikai');
        self::assertSame(SiteAddress::LOCAL_LEAK, $check['status']);

        foreach (['http://localhost', 'http://shop.test', 'http://x.local', 'http://yikai-jixie.yikai', 'http://192.168.1.10'] as $local) {
            self::assertSame(SiteAddress::LOCAL_LEAK, SiteAddress::inspect($local, 'https://www.example.com')['status'], $local);
        }
    }

    public function testMatchingAddressesIgnoreSchemeCaseAndTrailingSlash(): void
    {
        foreach ([
            ['https://www.example.com', 'http://www.example.com'],      // 反代后请求常显示为 http
            ['https://WWW.Example.com/', 'https://www.example.com'],
            ['https://demo.yikaicms.com/yikai-zhuangshi.yikai/', 'https://demo.yikaicms.com/yikai-zhuangshi.yikai'],
        ] as [$configured, $current]) {
            self::assertSame(SiteAddress::MATCH, SiteAddress::inspect($configured, $current)['status'], $configured);
        }
    }

    /** 子目录部署时只填了域名，也算对不上——提示把目录补上。 */
    public function testMissingSubdirectoryIsAMismatch(): void
    {
        self::assertSame(SiteAddress::MISMATCH,
            SiteAddress::inspect('https://demo.yikaicms.com', 'https://demo.yikaicms.com/yikai-zhuangshi.yikai')['status']);
    }

    /** 换了域名、或经反代从内网访问：只提醒，不是泄漏。 */
    public function testOtherDifferencesAreMismatchNotLeak(): void
    {
        self::assertSame(SiteAddress::MISMATCH, SiteAddress::inspect('https://new.example.com', 'https://old.example.com')['status']);
        self::assertSame(SiteAddress::MISMATCH,
            SiteAddress::inspect('https://www.example.com', 'http://127.0.0.1:8080')['status'],
            '填的是公网地址、当前从本机访问（开发中或反代后）不算泄漏');
        self::assertSame(SiteAddress::MISMATCH, SiteAddress::inspect('https://example.com:8443', 'https://example.com')['status'], '端口不同');
    }

    public function testEmptyAndUnknown(): void
    {
        self::assertSame(SiteAddress::EMPTY, SiteAddress::inspect('', 'https://www.example.com')['status']);
        self::assertSame(SiteAddress::EMPTY, SiteAddress::inspect('  ', 'https://www.example.com')['status']);
        self::assertSame(SiteAddress::UNKNOWN, SiteAddress::inspect('https://www.example.com', '')['status'], '命令行下取不到当前地址');
    }

    /** 与站点体检「开发地址不要求 HTTPS」是同一个定义，改一处两边一起变。 */
    public function testLocalHostDefinition(): void
    {
        foreach (['localhost', 'a.test', 'a.local', 'yikaicms.yikai', '127.0.0.1', '10.0.0.5', '[::1]'] as $host) {
            self::assertTrue(SiteAddress::isLocalHost($host), $host);
        }
        foreach (['www.example.com', 'demo.yikaicms.com', 'testing.com', 'local.example.com'] as $host) {
            self::assertFalse(SiteAddress::isLocalHost($host), $host);
        }
    }
}
