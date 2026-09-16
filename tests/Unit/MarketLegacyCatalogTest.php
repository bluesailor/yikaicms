<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketCatalogRequest.php';
require_once ROOT_PATH . '/includes/ThemeMarket.php';

final class MarketLegacyCatalogTest extends TestCase
{
    private function item(string $kind): array
    {
        return [
            'slug' => 'sample', 'name' => 'Sample', 'version' => '1.0.0',
            'package' => 'sample-v1.0.0.zip', 'size_kb' => 1,
            'requires_cms' => '>=1.0.0', 'requires_php' => '>=8.0.0',
            'hash' => 'sha256:' . str_repeat('a', 64), 'sig' => base64_encode('test-signature'),
            'download_url' => 'https://update.yikaicms.com/packages/' . $kind . '/sample-v1.0.0.zip',
        ];
    }

    private function body(string $kind, array $items, array $extra = []): string
    {
        return json_encode(['code' => 0, 'data' => [$kind => $items] + $extra], JSON_THROW_ON_ERROR);
    }

    public function testOnlyUnversionedOfficialCatalogsUseLegacyAdapter(): void
    {
        foreach (['themes', 'plugins'] as $kind) {
            $item = $this->item($kind);
            $result = MarketCatalogRequest::decode($this->body($kind, [$item]), $kind);
            self::assertSame(1, $result['data']['protocol_version']);
            self::assertSame('official', $result['data'][$kind][0]['source']);
            self::assertSame($item['download_url'], $result['data'][$kind][0]['download_url']);
            foreach ([null, 1, '2', 3] as $protocol) {
                self::assertNull(MarketCatalogRequest::decode($this->body($kind, [$item], ['protocol_version' => $protocol]), $kind));
            }
            self::assertNull(MarketCatalogRequest::decode('{"code":403,"data":{"' . $kind . '":[]}}', $kind));
            self::assertNull(MarketCatalogRequest::decode('<html>Unavailable</html>', $kind));
        }
    }

    public function testLegacyCannotSmuggleCommunityOrUntrustedPackages(): void
    {
        foreach (['themes', 'plugins'] as $kind) {
            foreach ([
                ['source' => 'community'], ['source' => 'unknown'], ['hash' => 'bad'], ['sig' => '!'],
                ['download_url' => 'https://other.example/test.zip'],
                ['download_url' => $this->item($kind)['download_url'] . '?token=anything'],
                ['download_url' => 'https://update.yikaicms.com/api/market/download.php?token=aaa.bbb'],
                ['package' => 'other-v1.0.0.zip'], ['version' => '1.0.0/../'],
            ] as $override) {
                $response = MarketCatalogRequest::decode($this->body($kind, [array_replace($this->item($kind), $override)]), $kind);
                self::assertSame([], $response['data'][$kind], json_encode($override));
            }
        }
    }

    public function testLegacyPaidDenialsKeepDeliveryDisabled(): void
    {
        foreach (['themes', 'plugins'] as $kind) {
            $item = array_replace($this->item($kind), ['paid' => true, 'locked_reason' => 'license_expired']);
            $response = MarketCatalogRequest::decode($this->body($kind, [$item]), $kind);
            $locked = $response['data'][$kind][0];
            self::assertSame('license_expired', $locked['locked_reason']);
            self::assertSame('', $locked['download_url']);
            self::assertArrayNotHasKey('hash', $locked);
            self::assertArrayNotHasKey('sig', $locked);
        }
    }

    public function testV2RestrictionsAreNotReinterpretedAsLegacy(): void
    {
        $item = ['slug' => 'sample', 'source' => 'community', 'locked_reason' => 'rate_limited', 'download_url' => ''];
        $response = MarketCatalogRequest::decode($this->body('plugins', [$item], ['protocol_version' => 2]), 'plugins');
        self::assertSame(2, $response['data']['protocol_version']);
        self::assertSame($item, $response['data']['plugins'][0]);
    }

    public function testThemeCompatibilityUsesOneRequestAndKeepsSignatureVerification(): void
    {
        if (!defined('CMS_VERSION')) define('CMS_VERSION', '1.20.0');
        $calls = 0;
        $body = $this->body('themes', [$this->item('themes')]);
        $response = ThemeMarket::request('', static function (string $url) use (&$calls, $body): string {
            self::assertStringStartsWith(ThemeMarket::API . '?', $url);
            ++$calls;
            return $body;
        });
        self::assertSame(1, $calls);
        self::assertSame(1, $response['data']['protocol_version']);
        self::assertCount(1, $response['data']['themes']);
        $item = $response['data']['themes'][0];
        self::assertFalse(ThemeMarket::verifyPackageSignature($item['slug'], $item['version'], $item['hash'], $item['sig'], ''));
    }
}
