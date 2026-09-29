<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ClientIpResolver.php';
require_once ROOT_PATH . '/plugins/geo-block/GeoBlock.php';

/** 地区访问限制插件：APNIC 数据构建、区间查找、拦截判定、跳转校验与提示页。 */
final class GeoBlockPluginTest extends TestCase
{
    private const DELEGATED = <<<'TXT'
2|apnic|20260928|70000|19830613|20260927|+1000
apnic|*|ipv4|*|50000|summary
apnic|CN|ipv4|1.0.1.0|256|20110414|allocated
apnic|CN|ipv4|1.0.2.0|512|20110414|allocated
apnic|AU|ipv4|1.1.1.0|256|20110811|assigned
apnic|CN|ipv4|114.112.0.0|524288|20100528|allocated
apnic|HK|ipv4|1.32.0.0|8192|20110111|allocated
apnic|CN|ipv4|223.5.0.0|65536|20100407|allocated
apnic|CN|ipv4|10.0.0.0|256|20100407|available
apnic|CN|ipv6|240e::|20|20131114|allocated
apnic|CN|ipv6|2400:3200::|32|20090707|allocated
apnic|JP|ipv6|2001:200::|32|19990813|allocated
TXT;

    private function ranges(): GeoBlockRanges
    {
        $built = GeoBlockRanges::buildFromDelegated(self::DELEGATED);
        return new GeoBlockRanges($built['ipv4'], $built['ipv6']);
    }

    /** @return array<string,mixed> */
    private function settings(array $override = []): array
    {
        return $override + [
            'enabled' => true, 'action' => 'block', 'redirect_url' => '', 'messages' => [],
            'allow_ips' => '', 'exempt_paths' => '', 'allow_admins' => true, 'cdn_header' => false,
        ];
    }

    public function testBuildsMergedRangesFromApnicDelegatedStats(): void
    {
        $built = GeoBlockRanges::buildFromDelegated(self::DELEGATED);
        // 1.0.1.0/24 与 1.0.2.0/23 相邻合并；「available」与其他国家丢弃
        self::assertSame(3, intdiv(strlen($built['ipv4']), 8));
        self::assertSame(2, intdiv(strlen($built['ipv6']), 32));
        self::assertSame(256 + 512 + 524288 + 65536, $built['ipv4_addresses']);
        self::assertSame('2026-09-27', $built['date']);
        self::assertSame(['ipv4' => 3, 'ipv6' => 2], $this->ranges()->counts());
    }

    public function testLooksUpMainlandAddressesOnly(): void
    {
        $ranges = $this->ranges();
        foreach (['1.0.1.0', '1.0.3.255', '114.114.114.114', '223.5.5.5', '240e:3a1::1', '2400:3200::1', '::ffff:114.114.114.114'] as $ip) {
            self::assertTrue($ranges->contains($ip), $ip);
        }
        // 港澳台与其他地区、边界外一位、保留地址（即使被标成 CN available）、非法输入
        foreach (['1.0.0.255', '1.0.4.0', '1.1.1.1', '1.32.0.1', '8.8.8.8', '10.0.0.1', '2001:200::1', '2400:3201::', 'not-an-ip', ''] as $ip) {
            self::assertFalse($ranges->contains($ip), $ip);
        }
        self::assertTrue((new GeoBlockRanges('', ''))->isEmpty());
        self::assertFalse((new GeoBlockRanges('bad', ''))->contains('1.0.1.1'), 'corrupt tables never match');
    }

    public function testBundledDataCoversWellKnownAddresses(): void
    {
        $ranges = GeoBlockRanges::bundled();
        if ($ranges->isEmpty()) {
            self::markTestSkipped('bundled APNIC data not generated yet');
        }
        foreach (['114.114.114.114', '223.5.5.5', '240e::1'] as $ip) {
            self::assertTrue($ranges->contains($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '203.198.7.66', '168.95.1.1'] as $ip) { // 美、澳、港、台
            self::assertFalse($ranges->contains($ip), $ip);
        }
    }

    public function testDecisionOrderAndExemptions(): void
    {
        $ranges = $this->ranges();
        $cn = ['ip' => '114.114.114.114', 'path' => '/news/'];
        self::assertSame(['block' => true, 'reason' => 'mainland_ip'], GeoBlock::decide($cn, $this->settings(), true, $ranges));
        self::assertSame('disabled', GeoBlock::decide($cn, $this->settings(['enabled' => false]), true, $ranges)['reason']);
        self::assertSame('unlicensed', GeoBlock::decide($cn, $this->settings(), false, $ranges)['reason']);
        self::assertSame('cli', GeoBlock::decide($cn + ['cli' => true], $this->settings(), true, $ranges)['reason']);
        self::assertSame('not_mainland', GeoBlock::decide(['ip' => '8.8.8.8', 'path' => '/'], $this->settings(), true, $ranges)['reason']);
        self::assertSame('unknown_ip', GeoBlock::decide(['ip' => '', 'path' => '/'], $this->settings(), true, $ranges)['reason']);
        // 服务器自身：内网、回环、本机公网地址
        self::assertSame('local', GeoBlock::decide(['ip' => '127.0.0.1', 'path' => '/'], $this->settings(), true, $ranges)['reason']);
        self::assertSame('local', GeoBlock::decide($cn + ['server_addr' => '114.114.114.114'], $this->settings(), true, $ranges)['reason']);
        // 已登录管理员（可关）
        self::assertSame('admin', GeoBlock::decide($cn + ['admin' => true], $this->settings(), true, $ranges)['reason']);
        self::assertTrue(GeoBlock::decide($cn + ['admin' => true], $this->settings(['allow_admins' => false]), true, $ranges)['block']);
        // 放行路径（前缀）与永远放行的计划任务
        self::assertSame('path', GeoBlock::decide(['path' => '/api/v1/items'] + $cn, $this->settings(['exempt_paths' => "/api/v1/\nbad path"]), true, $ranges)['reason']);
        self::assertTrue(GeoBlock::decide(['path' => '/apiv1'] + $cn, $this->settings(['exempt_paths' => '/api/v1/']), true, $ranges)['block']);
        self::assertSame('path', GeoBlock::decide(['path' => '/cron.php'] + $cn, $this->settings(), true, $ranges)['reason']);
        // 放行 IP（网段）
        self::assertSame('allowlist', GeoBlock::decide($cn, $this->settings(['allow_ips' => "114.114.0.0/16"]), true, $ranges)['reason']);
    }

    public function testCdnCountryHeaderIsTrustedOnlyFromTrustedProxies(): void
    {
        $ranges = $this->ranges();
        $settings = $this->settings(['cdn_header' => true]);
        $foreign = ['ip' => '8.8.8.8', 'path' => '/', 'cdn_country' => 'CN'];
        self::assertSame('cdn_country', GeoBlock::decide($foreign + ['remote_trusted' => true], $settings, true, $ranges)['reason']);
        self::assertTrue(GeoBlock::decide($foreign + ['remote_trusted' => true], $settings, true, $ranges)['block']);
        // 未经可信代理：伪造的头无效，回到 IP 判定
        self::assertFalse(GeoBlock::decide($foreign, $settings, true, $ranges)['block']);
        self::assertFalse(GeoBlock::decide(['ip' => '114.114.114.114', 'path' => '/', 'cdn_country' => 'US', 'remote_trusted' => true], $settings, true, $ranges)['block']);
        // 功能未开时，即使来自可信代理也不采信
        self::assertTrue(GeoBlock::decide(['ip' => '114.114.114.114', 'path' => '/', 'cdn_country' => 'US', 'remote_trusted' => true], $this->settings(), true, $ranges)['block']);
    }

    public function testRedirectValidationPathsAndBlockedPage(): void
    {
        self::assertSame('https://example.cn/', GeoBlock::validRedirect(' https://example.cn/ ', 'www.example.com'));
        self::assertNull(GeoBlock::validRedirect('https://www.example.com/x', 'www.example.com:443'), 'same host would loop');
        self::assertNull(GeoBlock::validRedirect('javascript:alert(1)', 'a.com'));
        self::assertNull(GeoBlock::validRedirect('//evil.com', 'a.com'));
        self::assertSame(['/api/', '/sitemap.xml'], GeoBlock::parsePaths("/api/\n/sitemap.xml\nno-slash\n/a b\n/api/"));

        $page = GeoBlock::blockedPage('Acme <Co>', "Line 1\n\"quoted\"", 'en', '114.114.114.114');
        self::assertStringContainsString('<title>403 · Acme &lt;Co&gt;</title>', $page);
        self::assertStringContainsString('&quot;quoted&quot;', $page);
        self::assertStringContainsString('noindex', $page);
        self::assertStringContainsString('114.114.114.114', $page);
        self::assertSame('Custom', GeoBlock::message(['messages' => ['en' => ' Custom ']], 'en'));
    }

    public function testPackageMetadataAndLanguagePacks(): void
    {
        $meta = json_decode((string) file_get_contents(ROOT_PATH . '/plugins/geo-block/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pro', $meta['tier']);
        self::assertSame('geo-block', $meta['module']);
        self::assertTrue(GeoBlock::supports('2.0.3'));
        self::assertFalse(GeoBlock::supports('1.25.0'));
        $keys = null;
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/plugins/geo-block/lang/' . $lang . '.php';
            self::assertIsArray($strings);
            $keys ??= array_keys($strings);
            self::assertSame($keys, array_keys($strings), $lang . ' has the same keys');
        }
        // 付费插件不进核心包
        self::assertStringContainsString('"plugins/geo-block"', (string) file_get_contents(ROOT_PATH . '/build.sh'));
        // 编辑器/前台之外的构建工具只能在命令行运行
        self::assertStringContainsString("PHP_SAPI !== 'cli'", (string) file_get_contents(ROOT_PATH . '/plugins/geo-block/tools/build-data.php'));
    }
}
