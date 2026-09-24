<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SiteTemplateMarket;

require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/SiteTemplateMarket.php';

final class SiteTemplateMarketTest extends TestCase
{
    private function item(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'yikai-auto', 'name' => 'Auto parts', 'version' => '1.0.1', 'cms' => CMS_VERSION,
            'format_version' => 1, 'category' => 'trade', 'requires_php' => '>=8.0.0', 'status' => 'published', 'tier' => 'free',
            'package' => 'yikai-auto-site-v1.0.1.zip',
            'download_url' => 'https://update.yikaicms.com/packages/site-templates/yikai-auto-site-v1.0.1.zip',
            'screenshot' => 'https://update.yikaicms.com/assets/site-templates/yikai-auto/1.0.1/preview.webp',
            'hash' => 'sha256:' . hash('sha256', 'package'), 'sig' => base64_encode('fixture-signature'),
            'size_bytes' => 7,
        ], $overrides);
    }

    private function response(array $items): string
    {
        return json_encode(['code' => 0, 'data' => ['protocol_version' => 1, 'updated_at' => '2026-09-22', 'templates' => $items]], JSON_THROW_ON_ERROR);
    }

    public function testCatalogUsesDedicatedProtocolAndNeverSendsLicenseSecrets(): void
    {
        $requested = '';
        $result = SiteTemplateMarket::request(function (string $url) use (&$requested): string {
            $requested = $url;
            return $this->response([$this->item(), $this->item(), $this->item(['slug' => '../bad'])]);
        });
        self::assertCount(1, $result['templates']);
        self::assertSame('', $result['templates'][0]['blocked_reason']);
        self::assertSame(SiteTemplateMarket::API, strtok($requested, '?'));
        parse_str((string) parse_url($requested, PHP_URL_QUERY), $query);
        self::assertSame(['protocol_version' => '1', 'cms_version' => CMS_VERSION, 'php_version' => PHP_VERSION, 'format_versions' => implode(',', \SiteTemplateArchive::SUPPORTED_VERSIONS)], $query);
        self::assertNull(SiteTemplateMarket::request(static fn(): string => '{"code":0,"data":{"protocol_version":2,"templates":[]}}'));
        self::assertNull(SiteTemplateMarket::request(static fn(): string => str_repeat('x', SiteTemplateMarket::MAX_CATALOG_BYTES + 1)));
    }

    public function testIncompatibleUnpublishedAndUnsignedPackagesCannotDownload(): void
    {
        foreach ([
            ['cms' => '1.20.0'], ['cms' => '99.0.0'], ['requires_php' => '>=99.0.0'], ['status' => 'draft'],
            ['format_version' => 99], ['tier' => 'pro'], ['sig' => ''], ['hash' => 'sha256:nope'], ['size_bytes' => 33554433],
            ['download_url' => 'https://evil.test/package.zip'],
            ['package' => 'other.zip'], ['download_url' => 'http://update.yikaicms.com/packages/site-templates/yikai-auto-site-v1.0.1.zip'],
        ] as $override) {
            $item = SiteTemplateMarket::normalize($this->item($override));
            self::assertNotNull($item);
            self::assertNotSame('', $item['blocked_reason']);
            self::assertSame('', $item['download_url']);
            self::assertSame('', $item['hash']);
            self::assertSame('', $item['sig']);
        }
        self::assertNull(SiteTemplateMarket::normalize($this->item(['cms' => '>=1.20.1'])));
        self::assertSame('', SiteTemplateMarket::normalize($this->item(['screenshot' => 'https://evil.test/pixel.webp']))['screenshot']);
    }

    /** Test keys exist only in memory and cannot authorize the official market. */
    private function signed(string $bytes, bool $legacy = false): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($config)) $options['config'] = $config;
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $item = $this->item(['hash' => 'sha256:' . hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
        $canonical = $legacy
            ? 'site-template|' . $item['slug'] . '|' . $item['version'] . '|' . $item['cms'] . '|' . $item['format_version'] . '|' . $item['hash']
            : SiteTemplateMarket::canonical($item);
        self::assertTrue(openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256));
        $item['sig'] = base64_encode($signature);
        return [$item, openssl_pkey_get_details($key)['key']];
    }

    public function testV2SignatureBindsAuthorizationDeliveryAndArchiveFieldsAndRejectsV1(): void
    {
        [$item, $public] = $this->signed('package');
        self::assertTrue(SiteTemplateMarket::verifySignature($item, $public));
        self::assertFalse(SiteTemplateMarket::verifySignature(array_replace($item, ['hash' => 'sha256:' . str_repeat('a', 64)]), $public));
        self::assertFalse(SiteTemplateMarket::verifySignature(array_replace($item, ['cms' => '1.20.0']), $public));
        foreach ([
            ['requires_php' => '>=8.1.0'], ['tier' => 'pro'], ['status' => 'draft'],
            ['package' => 'other.zip'], ['size_bytes' => 8],
        ] as $change) {
            self::assertFalse(SiteTemplateMarket::verifySignature(array_replace($item, $change), $public));
        }
        [$legacy, $legacyPublic] = $this->signed('package', true);
        self::assertFalse(SiteTemplateMarket::verifySignature($legacy, $legacyPublic));
    }

    public function testDownloadStreamsVerifiedBytesAndRejectsCorruptionRedirectsAndOverflow(): void
    {
        [$item, $public] = $this->signed('package');
        foreach (['valid', 'corrupt', 'redirect', 'overflow', 'short', 'declared-overflow'] as $scenario) {
            $path = tempnam(sys_get_temp_dir(), 'yk-market-test-');
            try {
                $transport = static function (string $url, callable $accept, callable $write, int $timeout) use ($scenario): array {
                    self::assertSame('https://update.yikaicms.com/packages/site-templates/yikai-auto-site-v1.0.1.zip', $url);
                    self::assertSame(60, $timeout);
                    if ($scenario === 'declared-overflow') {
                        self::assertFalse($accept(100));
                        return ['status' => 200, 'error' => 'size'];
                    }
                    self::assertTrue($accept(7));
                    $body = match ($scenario) { 'corrupt' => 'tamper!', 'overflow' => 'package+', 'short' => 'pack', default => 'package' };
                    $write($body);
                    return ['status' => $scenario === 'redirect' ? 302 : 200, 'error' => ''];
                };
                try {
                    SiteTemplateMarket::download($item, $path, $public, $transport);
                    self::assertSame('valid', $scenario);
                    self::assertSame('package', file_get_contents($path));
                } catch (RuntimeException $error) {
                    self::assertNotSame('valid', $scenario);
                    self::assertContains($error->getMessage(), ['st_market_hash', 'st_market_download']);
                    self::assertFileDoesNotExist($path);
                }
            } finally { if (is_file($path)) unlink($path); }
        }
    }

    public function testInvalidSignatureStopsBeforeNetwork(): void
    {
        [$item, $public] = $this->signed('package');
        $item['sig'] = base64_encode('invalid');
        $called = false;
        try {
            SiteTemplateMarket::download($item, sys_get_temp_dir() . '/unused-market-test.zip', $public,
                static function () use (&$called): array { $called = true; return ['status' => 200, 'error' => '']; });
            self::fail('Unsigned package accepted');
        } catch (RuntimeException $error) {
            self::assertSame('st_market_signature', $error->getMessage());
            self::assertFalse($called);
        }
    }

    public function testMarketPreparesAnExistingImporterPreviewWithoutApplyingOrTrusting(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_template_market.php');
        self::assertStringContainsString("requirePermission('*')", $page);
        self::assertStringContainsString('if ($isPost) verifyCsrf()', $page);
        self::assertStringContainsString('SiteTemplateMarket::verifyArchive($temporary, $selected)', $page);
        // 已有内容的站只凭勾选确认放行（replace_existing），仍然只做预览、不直接导入
        self::assertStringContainsString('$service->prepare($temporary, getAdminId(), $replaceExisting)', $page);
        self::assertStringContainsString("redirect('/admin/site_templates.php')", $page);
        self::assertStringNotContainsString('->apply(', $page);
        self::assertStringNotContainsString('->markFreshInstall(', $page);
        self::assertStringNotContainsString("post('download_url')", $page);
        self::assertStringContainsString('!$isPost', $page);
    }
}
