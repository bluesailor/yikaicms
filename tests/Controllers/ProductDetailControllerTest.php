<?php
/**
 * Tests for ProductDetailController — product.php 从内联取数迁到此。
 *
 * 覆盖：已发布产品返回 + 分类/相关/上下篇、浏览量自增、未发布/不存在→null、
 * 图片组解析（JSON / 换行 / 封面补头）、规格解析（JSON / key:value 行）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/detail/ProductDetailController.php';
// addProductViews / getProductCategory 等全局助手 shim 在 _fixtures/helpers.php（全局命名空间）。
require_once __DIR__ . '/_fixtures/helpers.php';

class ProductDetailControllerTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE product_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT, slug TEXT, status INTEGER DEFAULT 1
            )",
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NOT NULL DEFAULT 0,
                title TEXT NOT NULL, slug TEXT, summary TEXT, content TEXT,
                cover TEXT, images TEXT, specs TEXT, tags TEXT,
                price REAL DEFAULT 0,
                status INTEGER DEFAULT 1,
                is_recommend INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    private function seed(): void
    {
        $this->insertRow('product_categories', ['name' => 'Pumps', 'slug' => 'pumps', 'status' => 1]);
        $this->insertRow('products', ['category_id' => 1, 'title' => 'Pump A', 'slug' => 'pa', 'status' => 1, 'views' => 3,
            'publish_time' => 100, 'images' => '["/a.jpg","/b.jpg"]', 'specs' => "功率:5kW\n重量:20kg", 'cover' => '/cover.jpg']);
        $this->insertRow('products', ['category_id' => 1, 'title' => 'Pump B', 'slug' => 'pb', 'status' => 1, 'publish_time' => 200]);
        $this->insertRow('products', ['category_id' => 1, 'title' => 'Draft', 'status' => 0]);
    }

    public function testReturnsPublishedProductWithRelations(): void
    {
        $this->seed();
        $vars = (new \ProductDetailController())->prepare(1);

        $this->assertNotNull($vars);
        $this->assertSame('Pump A', $vars['product']['title']);
        $this->assertSame('Pumps', $vars['productCategory']['name']);
        $relTitles = array_column($vars['relatedProducts'], 'title');
        $this->assertNotContains('Pump A', $relTitles);        // 排除自身
        $this->assertNotContains('Draft', $relTitles);         // 排除未发布
    }

    public function testIncrementsViews(): void
    {
        $this->seed();
        (new \ProductDetailController())->prepare(1);
        $this->assertSame(4, (int) db()->fetchColumn('SELECT views FROM products WHERE id = 1'));
    }

    public function testReturnsNullForUnpublishedOrMissing(): void
    {
        $this->seed();
        $this->assertNull((new \ProductDetailController())->prepare(3));   // Draft
        $this->assertNull((new \ProductDetailController())->prepare(999)); // missing
        $this->assertNull((new \ProductDetailController())->prepare(0));   // id<=0
    }

    public function testParsesImagesJsonAndPrependsCover(): void
    {
        $this->seed();
        $vars = (new \ProductDetailController())->prepare(1);
        // 封面补到开头，其后是 JSON 数组两张
        $this->assertSame(['/cover.jpg', '/a.jpg', '/b.jpg'], $vars['productImages']);
    }

    public function testParsesSpecsKeyValueLines(): void
    {
        $this->seed();
        $vars = (new \ProductDetailController())->prepare(1);
        $this->assertSame([
            ['name' => '功率', 'value' => '5kW'],
            ['name' => '重量', 'value' => '20kg'],
        ], $vars['specs']);
    }

    public function testEmptyImagesAndSpecsYieldEmptyArrays(): void
    {
        $this->seed();
        $vars = (new \ProductDetailController())->prepare(2); // Pump B 无 images/specs/cover
        $this->assertSame([], $vars['productImages']);
        $this->assertSame([], $vars['specs']);
    }
}
