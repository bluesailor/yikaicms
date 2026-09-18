<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/License.php';

/**
 * 授权缓存的两类既有缺陷（2026-09-17 修复）：
 * - 复制与续期：license_state 可从 A 站复制到 B 站；未签名的 checked_at 改到未来即永不复查。
 * - 前台阻塞：缓存过期且授权服务器不可达时，前台每个请求都同步等待最长约 12 秒。
 */
final class LicenseCacheIntegrityTest extends TestCase
{
    private const NOW = 1789600000;

    /** @return array<string,mixed> */
    private function cache(array $data = [], array $extra = []): array
    {
        return array_replace([
            'data' => array_replace([
                'valid' => true, 'reason' => 'ok', 'plan' => 'pro', 'modules' => ['seo-pro'],
                'expires_at' => '2027-09-01', 'expired' => false,
                'domain' => 'site-a.example', 'ts' => date('Y-m-d H:i:s', self::NOW - 3600),
            ], $data),
            'sig' => 'signed',
            'checked_at' => self::NOW - 3600,
        ], $extra);
    }

    public function testCacheCopiedFromAnotherDomainIsRejected(): void
    {
        $cache = $this->cache();
        self::assertTrue(\license_cache_belongs_here($cache, 'site-a.example', 'KEY-A'));
        self::assertTrue(\license_cache_belongs_here($cache, 'WWW.Site-A.example:8443', 'KEY-A'), 'www、大小写与端口不影响同站判断');
        self::assertFalse(\license_cache_belongs_here($cache, 'site-b.example', 'KEY-A'));
        // 命令行等拿不到域名的上下文不据此拒绝
        self::assertTrue(\license_cache_belongs_here($cache, '', 'KEY-A'));
    }

    public function testCacheFromADifferentKeyIsNotReused(): void
    {
        $cache = $this->cache([], ['key_hash' => hash('sha256', 'KEY-A')]);
        self::assertTrue(\license_cache_belongs_here($cache, 'site-a.example', 'KEY-A'));
        self::assertFalse(\license_cache_belongs_here($cache, 'site-a.example', 'KEY-B'));
        // 升级前写入的缓存没有 key_hash：沿用到下一次成功校验
        self::assertTrue(\license_cache_belongs_here($this->cache(), 'site-a.example', 'KEY-B'));
    }

    public function testFutureCheckedAtCannotExtendTheCacheBeyondTheSignedTimestamp(): void
    {
        $signedTs = self::NOW - 20 * 86400;
        $forged = $this->cache(['ts' => date('Y-m-d H:i:s', $signedTs)], ['checked_at' => self::NOW - 60]);
        self::assertSame($signedTs + LICENSE_TS_SLACK, \license_cache_checked_at($forged, self::NOW));

        $future = $this->cache(['ts' => ''], ['checked_at' => self::NOW + 10 * 365 * 86400]);
        self::assertSame(0, \license_cache_checked_at($future, self::NOW), '未来时间视为从未校验');

        $honest = $this->cache();
        self::assertSame(self::NOW - 3600, \license_cache_checked_at($honest, self::NOW));
    }

    public function testFrontendRequestsReadTheCacheInsteadOfCallingTheServer(): void
    {
        $stale = self::NOW - LICENSE_CHECK_TTL - 60;
        // 后台请求在缓存过期后续期
        self::assertTrue(\license_should_contact_server(false, true, true, $stale, 0, self::NOW));
        // 前台访客请求：过期但仍在宽限期内 → 不发请求
        self::assertFalse(\license_should_contact_server(false, false, true, $stale, 0, self::NOW));
        // 前台：超出宽限期或从未校验 → 才发请求
        self::assertTrue(\license_should_contact_server(false, false, true, self::NOW - LICENSE_GRACE - 60, 0, self::NOW));
        self::assertTrue(\license_should_contact_server(false, false, false, 0, 0, self::NOW));
        // 缓存新鲜时任何请求都不发
        self::assertFalse(\license_should_contact_server(false, true, true, self::NOW - 60, 0, self::NOW));
    }

    public function testFailedCheckBacksOffUnlessForced(): void
    {
        $retryAfter = self::NOW + 600;
        self::assertFalse(\license_should_contact_server(false, true, false, 0, $retryAfter, self::NOW));
        self::assertFalse(\license_should_contact_server(false, false, true, self::NOW - LICENSE_GRACE - 60, $retryAfter, self::NOW));
        // 授权管理页「立即校验」不受退避影响
        self::assertTrue(\license_should_contact_server(true, true, true, self::NOW - 60, $retryAfter, self::NOW));
        // 退避过期后恢复
        self::assertTrue(\license_should_contact_server(false, true, false, 0, self::NOW - 1, self::NOW));
    }

    public function testDomainNormalizationMatchesTheServer(): void
    {
        self::assertSame('client.com', \license_normalize_domain('https://WWW.Client.com:443/path?q=1'));
        self::assertSame('shop.client.com', \license_normalize_domain('shop.client.com'));
        self::assertSame('', \license_normalize_domain(''));
    }
}
