<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ThemeMarket;

require_once ROOT_PATH . '/includes/ThemeMarket.php';

final class ThemeMarketTest extends TestCase
{
    public function testCatalogIsNormalizedAndMalformedEntriesAreIgnored(): void
    {
        $response = ThemeMarket::request('', static fn (string $url): string => json_encode([
            'code' => 0,
            'data' => ['updated_at' => '2026-09-01', 'themes' => [
                ['slug' => 'business', 'name' => 'Business', 'version' => '1.0.1'],
                ['slug' => '../bad', 'name' => 'Bad', 'version' => '9.0.0'],
                ['slug' => 'business', 'name' => 'Duplicate', 'version' => '2.0.0'],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertNotNull($response);
        self::assertSame('https://update.yikaicms.com/api/themes/list.php', $this->capturedUrl(''));
        self::assertCount(1, $response['data']['themes']);
        self::assertSame('business', $response['data']['themes'][0]['slug']);
    }

    public function testOnlyNewerInstalledThemesBecomeUpdates(): void
    {
        $updates = ThemeMarket::availableUpdates(
            ['business' => '1.0.0', 'default' => '1.0.0'],
            [
                ['slug' => 'business', 'name' => 'Business', 'version' => '1.0.1'],
                ['slug' => 'default', 'name' => 'Default', 'version' => '1.0.0'],
                ['slug' => 'trade', 'name' => 'Trade', 'version' => '2.0.0'],
            ]
        );

        self::assertCount(1, $updates);
        self::assertSame('1.0.0', $updates[0]['current_version']);
        self::assertSame('1.0.1', $updates[0]['latest_version']);
    }

    public function testInvalidResponseFailsClosed(): void
    {
        self::assertNull(ThemeMarket::request('', static fn (string $url): string => '<html>error</html>'));
        self::assertNull(ThemeMarket::request('', static fn (string $url): string => '{"code":1}'));
    }

    public function testPackageDownloadRejectsNonOfficialLocationsBeforeRequest(): void
    {
        self::assertNull(ThemeMarket::downloadPackage('http://update.yikaicms.com/packages/themes/business.zip'));
        self::assertNull(ThemeMarket::downloadPackage('https://evil.example/packages/themes/business.zip'));
        self::assertNull(ThemeMarket::downloadPackage('https://update.yikaicms.com/redirect?file=business.zip'));
        self::assertNull(ThemeMarket::downloadPackage('https://update.yikaicms.com/packages/themes/../business.zip'));
    }

    private function capturedUrl(string $query): string
    {
        $url = '';
        ThemeMarket::request($query, static function (string $value) use (&$url): string {
            $url = $value;
            return '{"code":0,"data":{"themes":[]}}';
        });
        return $url;
    }
}
