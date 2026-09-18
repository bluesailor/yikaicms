<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use MarketCatalogItems;
use MarketDownloadStatus;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketCatalogItems.php';
require_once ROOT_PATH . '/includes/MarketDownloadStatus.php';

final class MarketCatalogItemsTest extends TestCase
{
    public function testOfficialIdentityWinsRegardlessOfOrderVersionOrDownloadEligibility(): void
    {
        $official = ['slug' => 'business', 'version' => '1.0.0', 'locked_reason' => 'module_missing'];
        $community = ['slug' => 'business', 'source' => 'community', 'version' => '99.0.0', 'download_url' => 'unused'];
        foreach ([[$community, $official], [$official, $community]] as $items) {
            self::assertSame([$official], MarketCatalogItems::select($items));
        }
    }

    public function testDistinctCommunityItemsSupplementOfficialCatalogAndKindsRemainIndependent(): void
    {
        $official = ['slug' => 'business'];
        $community = ['slug' => 'community-theme', 'source' => 'community'];
        self::assertSame([$official, $community], MarketCatalogItems::select([$community, $official, $official, $community]));
        self::assertSame([$community], MarketCatalogItems::select([$community]));
        self::assertSame([], MarketCatalogItems::select([null, ['slug' => '../bad'], ['slug' => []],
            ['slug' => 'other', 'source' => 'untrusted'], ['slug' => 'null-source', 'source' => null]]));
    }

    public function testConflictingCommunityVersionsCannotBecomeArbitraryUpdateCandidates(): void
    {
        $first = ['slug' => 'example', 'source' => 'community', 'version' => '1.0.0',
            'paid' => false, 'entitled' => true, 'download_url' => 'secret', 'hash' => 'a', 'sig' => 'b'];
        $second = array_replace($first, ['version' => '2.0.0']);
        $items = MarketCatalogItems::select([$first, $second, $first]);
        self::assertCount(1, $items);
        self::assertSame('catalog_conflict', $items[0]['locked_reason']);
        self::assertFalse($items[0]['entitled']);
        self::assertSame('', $items[0]['download_url']);
        self::assertArrayNotHasKey('hash', $items[0]);
        self::assertArrayNotHasKey('sig', $items[0]);
        self::assertTrue(MarketDownloadStatus::decorate($items[0])['download_blocked']);
        self::assertSame([['slug' => 'example']], MarketCatalogItems::select([$first, $second, ['slug' => 'example']]));
    }

    public function testPluginBrowseAndInstallUseTheSameIdentitySelection(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/plugin.php');
        self::assertIsString($source);
        $browse = strpos($source, "case 'market_list':");
        $install = strpos($source, "case 'market_install':");
        self::assertIsInt($browse);
        self::assertIsInt($install);
        self::assertStringContainsString('MarketCatalogItems::select(', substr($source, $browse, $install - $browse));
        self::assertStringContainsString('MarketCatalogItems::select(', substr($source, $install));
    }
}
