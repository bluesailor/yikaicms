<?php
/**
 * Tests for ProductController — the most complex list-controller. Adds:
 *   - sort whitelist enforcement (invalid ?sort= falls back to default)
 *   - ?cat=<slug> resolves to product category id
 *   - top-level channel returns ALL products
 *   - sub-channel scopes to its own id
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/ProductController.php';
require_once __DIR__ . '/_fixtures/helpers.php';

class ProductControllerTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'product',
                status INTEGER DEFAULT 1
            )",
            "CREATE TABLE product_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT,
                status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN'
            )",
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, model TEXT,
                cover TEXT, price REAL DEFAULT 0,
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0,
                is_hot INTEGER DEFAULT 0, is_new INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0,
                tags TEXT,
                created_at TEXT, updated_at TEXT,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL,
                brand_id INTEGER DEFAULT 0
            )",
            "CREATE TABLE brands (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT, status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0
            )",
            "CREATE TABLE product_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT, group_name TEXT DEFAULT '',
                status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0
            )",
            "CREATE TABLE product_tag_map (
                product_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,
                PRIMARY KEY (product_id, tag_id)
            )",
        ];
    }

    private function seedFixture(): void
    {
        $this->insertRow('channels', ['name'=>'Products', 'slug'=>'products', 'type'=>'product', 'parent_id'=>0]);
        $this->insertRow('channels', ['name'=>'Sub-prod', 'slug'=>'sub',     'type'=>'product', 'parent_id'=>1]);

        $this->insertRow('product_categories', ['name'=>'Cat-A', 'slug'=>'cat-a']);
        $this->insertRow('product_categories', ['name'=>'Cat-B', 'slug'=>'cat-b']);

        $this->insertRow('products', ['category_id'=>1, 'title'=>'P-A1', 'status'=>1]);
        $this->insertRow('products', ['category_id'=>1, 'title'=>'P-A2', 'status'=>1]);
        $this->insertRow('products', ['category_id'=>2, 'title'=>'P-B1', 'status'=>1]);
        $this->insertRow('products', ['category_id'=>1, 'title'=>'Draft','status'=>0]);
    }

    public function testTopLevelChannelListsAllProducts(): void
    {
        $this->seedFixture();
        $vars = (new \ProductController())->prepare(
            ['id' => 1, 'type' => 'product', 'parent_id' => 0],
            $this->req()
        );
        $this->assertSame(0, $vars['productCategoryId']);    // 0 = no filter
        $this->assertSame(3, $vars['total']);                 // 3 active across cats
    }

    public function testCatSlugOverridesCategory(): void
    {
        $this->seedFixture();
        $vars = (new \ProductController())->prepare(
            ['id' => 1, 'type' => 'product', 'parent_id' => 0],
            $this->req(['cat' => 'cat-b'])
        );
        $this->assertSame(2, $vars['productCategoryId']);
        $this->assertSame(1, $vars['total']);
        $this->assertSame('P-B1', $vars['contents'][0]['title']);
    }

    public function testInvalidSortFallsBackToDefault(): void
    {
        $this->seedFixture();
        $vars = (new \ProductController())->prepare(
            ['id' => 1, 'type' => 'product', 'parent_id' => 0],
            $this->req(['sort' => 'malicious; DROP TABLE--'])
        );
        $this->assertSame('default', $vars['currentSort']);
        $this->assertSame('default', $vars['whereConditions']['sort']);
    }

    public function testValidSortPassesThrough(): void
    {
        $this->seedFixture();
        $vars = (new \ProductController())->prepare(
            ['id' => 1, 'type' => 'product', 'parent_id' => 0],
            $this->req(['sort' => 'newest'])
        );
        $this->assertSame('newest', $vars['currentSort']);
    }

    public function testEnabledSortsLoadedFromConfig(): void
    {
        $this->seedFixture();
        $GLOBALS['_test_config']['product_sort_options'] = '["default","price_asc"]';
        $vars = (new \ProductController())->prepare(
            ['id' => 1, 'type' => 'product', 'parent_id' => 0],
            $this->req()
        );
        $this->assertSame(['default', 'price_asc'], $vars['enabledSorts']);
        unset($GLOBALS['_test_config']['product_sort_options']);
    }

    public function testReturnsRequiredViewKeys(): void
    {
        $this->seedFixture();
        $vars = (new \ProductController())->prepare(
            ['id' => 1, 'type' => 'product', 'parent_id' => 0],
            $this->req()
        );
        foreach (['channel','channelId','productCategoryId','productCategory',
                  'currentSort','enabledSorts','whereConditions','contents','total'] as $k) {
            $this->assertArrayHasKey($k, $vars, "missing var: {$k}");
        }
    }

    // ---- 多条件筛选（facet）----

    private function seedFacets(): void
    {
        $this->seedFixture(); // P-A1=1, P-A2=2 (cat1); P-B1=3 (cat2); Draft=4 (status0)
        $pdo = db()->getPdo();
        $pdo->exec('UPDATE products SET brand_id=1, price=100 WHERE id=1');
        $pdo->exec('UPDATE products SET brand_id=2, price=300 WHERE id=2');
        $pdo->exec('UPDATE products SET brand_id=1, price=500 WHERE id=3');
        $this->insertRow('brands', ['name'=>'Acme']);    // 1
        $this->insertRow('brands', ['name'=>'Globex']);  // 2
        $this->insertRow('product_tags', ['name'=>'Steel',   'group_name'=>'Material']); // 1
        $this->insertRow('product_tags', ['name'=>'Plastic', 'group_name'=>'Material']); // 2
        $this->insertRow('product_tags', ['name'=>'Red',     'group_name'=>'Color']);    // 3
        foreach ([[1,1],[1,3],[2,2],[3,1]] as [$pid,$tid]) {
            $this->insertRow('product_tag_map', ['product_id'=>$pid, 'tag_id'=>$tid]);
        }
    }

    private function prepareTop(): array
    {
        return (new \ProductController())->prepare(
            ['id'=>1, 'type'=>'product', 'parent_id'=>0],
            $this->req()
        );
    }

    public function testFacetBrandFilterNarrowsResults(): void
    {
        $this->seedFacets();
        $_GET['brand'] = '1';                    // Acme → 商品 1 与 3
        $vars = $this->prepareTop();
        $this->assertSame(2, $vars['total']);
        $this->assertSame([1], $vars['selBrandIds']);
    }

    public function testFacetTagsAcrossGroupsAreAnded(): void
    {
        $this->seedFacets();
        $_GET['tag'] = '1,3';                     // Steel(Material) + Red(Color) → 组间 AND → 仅商品1
        $this->assertSame(1, $this->prepareTop()['total']);
    }

    public function testFacetTagsWithinGroupAreOred(): void
    {
        $this->seedFacets();
        $_GET['tag'] = '1,2';                     // Steel+Plastic 同为 Material → 组内 OR → 商品1,2,3
        $this->assertSame(3, $this->prepareTop()['total']);
    }

    public function testFacetPriceRangeFilter(): void
    {
        $this->seedFacets();
        $_GET['pmin'] = '200'; $_GET['pmax'] = '400';  // 仅 price=300 的商品2
        $this->assertSame(1, $this->prepareTop()['total']);
    }

    public function testFacetCombinedBrandAndTag(): void
    {
        $this->seedFacets();
        $_GET['brand'] = '1'; $_GET['tag'] = '3'; // brand Acme{1,3} ∩ Red{1} → 商品1
        $this->assertSame(1, $this->prepareTop()['total']);
    }

    public function testFacetDataExposedToView(): void
    {
        $this->seedFacets();
        $vars = $this->prepareTop();
        foreach (['facetBrands','facetTagGroups','facetPrice','filterActive'] as $k) {
            $this->assertArrayHasKey($k, $vars, "missing facet var: {$k}");
        }
        $this->assertArrayHasKey('Material', $vars['facetTagGroups']);
        $this->assertArrayHasKey('Color', $vars['facetTagGroups']);
        $this->assertFalse($vars['filterActive']);           // 无筛选参数
        $this->assertCount(2, $vars['facetBrands']);          // Acme + Globex（均有在售品）
    }

    protected function tearDown(): void
    {
        $_GET = [];   // facet 参数经 $_GET 传入，清理避免泄漏到其它测试
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function req(array $overrides = []): array
    {
        return array_merge(
            ['channelId'=>0,'slug'=>'','page'=>1,'perPage'=>12,'keyword'=>'','cat'=>'','sort'=>''],
            $overrides
        );
    }
}
