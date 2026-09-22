<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use ProductCatalogRequest;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/ProductCatalogRequest.php';

final class ProductCatalogRequestTest extends TestCase
{
    public function testNormalizeCanonicalizesEverySupportedFilter(): void
    {
        $query = ProductCatalogRequest::normalize([
            'page' => '0002',
            'keyword' => '  设备 A  ',
            'cat' => 'industrial-tools',
            'sort' => 'price_asc',
            'brand' => '9,2,9',
            'tag' => '8,3',
            'pmin' => '0010.5000',
            'pmax' => '0200.0000',
        ]);

        self::assertSame(2, $query['page']);
        self::assertSame('设备 A', $query['keyword']);
        self::assertSame('industrial-tools', $query['cat']);
        self::assertSame('price_asc', $query['sort']);
        self::assertSame('2,9', $query['brand']);
        self::assertSame([2, 9], $query['brand_ids']);
        self::assertSame('3,8', $query['tag']);
        self::assertSame('10.5', $query['pmin']);
        self::assertSame('200', $query['pmax']);
    }

    public function testInvalidAndOversizedValuesFallBackWithoutEnteringQueries(): void
    {
        $tooManyIds = implode(',', range(1, 51));
        $query = ProductCatalogRequest::normalize([
            'page' => '10001',
            'keyword' => str_repeat('x', 101),
            'cat' => '../private',
            'sort' => 'price;drop table',
            'brand' => $tooManyIds,
            'tag' => ['1'],
            'pmin' => '-1',
            'pmax' => '1e9',
        ]);

        self::assertSame(1, $query['page']);
        self::assertSame('', $query['keyword']);
        self::assertSame('', $query['cat']);
        self::assertSame('default', $query['sort']);
        self::assertSame([], $query['brand_ids']);
        self::assertSame([], $query['tag_ids']);
        self::assertSame('', $query['pmin']);
        self::assertSame('', $query['pmax']);
        self::assertSame([], ProductCatalogRequest::filterQuery($query, true));
    }

    public function testGeneratedUrlsKeepOnlyRouteIdentityAndCanonicalFilters(): void
    {
        $normalized = ProductCatalogRequest::normalize([
            'page' => '3', 'keyword' => 'bolt', 'cat' => 'parts',
            'brand' => '4,2', 'sort' => 'newest',
        ]);

        self::assertSame([
            'yk_route' => 'product_list',
            'id' => '7',
            'cat' => 'parts',
            'keyword' => 'bolt',
            'brand' => '2,4',
            'sort' => 'newest',
            'page' => '3',
        ], ProductCatalogRequest::urlQuery([
            'yk_route' => 'product_list', 'id' => 7, 'utm_source' => 'ignored', 'token' => 'ignored',
        ], $normalized));
        self::assertSame([], ProductCatalogRequest::routeQuery([
            'yk_route' => '../admin', 'id' => '0', 'slug' => '../private', 'lang' => 'bad',
        ]));
    }

    public function testCacheIdentityUsesTheSameCanonicalValues(): void
    {
        self::assertSame([
            'brand' => '2,9', 'page' => '2', 'pmin' => '10.5', 'sort' => 'newest',
        ], ProductCatalogRequest::normalizeCacheQuery([
            'pmin' => '0010.5000', 'sort' => 'newest', 'page' => '0002', 'brand' => '9,2,9',
        ]));
        self::assertSame([], ProductCatalogRequest::normalizeCacheQuery([
            'page' => '1', 'sort' => 'default', 'brand' => '', 'pmin' => '',
        ]));
        self::assertNull(ProductCatalogRequest::normalizeCacheQuery(['brand' => implode(',', range(1, 51))]));
        self::assertNull(ProductCatalogRequest::normalizeCacheQuery(['unknown' => '1']));
        self::assertSame([
            'id' => '7', 'keyword' => 'bolt', 'yk_route' => 'product_list',
        ], ProductCatalogRequest::normalizeCacheQuery([
            'keyword' => 'bolt', 'yk_route' => 'product_list', 'id' => '7',
        ]));
        self::assertNull(ProductCatalogRequest::normalizeCacheQuery(['yk_route' => '../admin']));
    }
}
