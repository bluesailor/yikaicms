<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/plugins/product-carousel/products.php';

final class ProductCarouselLanguageTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE product_categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT)',
            'CREATE TABLE products (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, category_id INTEGER,
                lang TEXT, translation_group_id INTEGER, status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'zh-CN', 2 => 'en', 3 => 'ja'] as $id => $lang) {
            $this->insertRow('product_categories', ['id' => $id, 'name' => $lang, 'slug' => 'category-' . $lang]);
        }
        foreach ([
            [10, 'zh-CN', null, 1, null], [11, 'en', 10, 1, null], [12, 'ja', 10, 1, null],
            [20, 'en', 0, 1, null], [21, 'zh-CN', 20, 1, null], [22, 'ja', 20, 1, null],
            [30, 'zh-CN', 30, 1, null], [31, 'en', 30, 0, null], [32, 'ja', 30, 1, 1],
            [40, 'zh-CN', 40, 0, null], [41, 'en', 40, 1, null],
            [50, 'zh-CN', 50, 1, 1], [51, 'en', 50, 1, null],
            [60, 'zh-CN', 60, 1, null], [70, 'en', 70, 1, null],
        ] as [$id, $lang, $group, $status, $deleted]) {
            $this->insertRow('products', ['id' => $id, 'title' => $lang . '-' . $id, 'slug' => 'shared-slug',
                'category_id' => array_search($lang, [1 => 'zh-CN', 2 => 'en', 3 => 'ja'], true),
                'lang' => $lang, 'translation_group_id' => $group, 'status' => $status, 'deleted_at' => $deleted]);
        }
    }

    public function testTranslationUsesGroupNotSharedSlugAndPreservesOrderAndDuplicates(): void
    {
        $rows = \productCarouselProducts([21, 10, 60, 30, 40, 50, 10, 20, 999], 'en');
        self::assertSame([20, 11, 60, 30, 11, 20], array_column($rows, 'id'));
        self::assertSame('category-en', $rows[1]['category_slug']);
        self::assertSame('en-11', $rows[1]['title']);
    }

    public function testLegacyRootWithoutBackfilledGroupRemainsReachable(): void
    {
        self::assertSame([10, 21], array_column(\productCarouselProducts([11, 20], 'zh-CN'), 'id'));
        self::assertSame([20], array_column(\productCarouselProducts([22], 'en'), 'id'));
    }

    public function testUnpublishedAndDeletedTranslationsFallBackWithoutHidingSource(): void
    {
        self::assertSame([12, 30, 60], array_column(\productCarouselProducts([10, 30, 60], 'ja'), 'id'));
        self::assertSame([30], array_column(\productCarouselProducts([30], 'en'), 'id'));
    }

    public function testUnavailableSourceIsNotResurrectedByItsPublishedTranslation(): void
    {
        self::assertSame([], \productCarouselProducts([40, 50, 999], 'en'));
        self::assertSame([], \productCarouselProducts([], 'ja'));
    }

    public function testRenderingDoesNotRewriteSelectionOrProductRows(): void
    {
        $ids = [10, 21, 30, 60];
        $before = db()->fetchAll('SELECT * FROM products ORDER BY id');
        foreach (['zh-CN', 'en', 'ja'] as $lang) \productCarouselProducts($ids, $lang);
        self::assertSame([10, 21, 30, 60], $ids);
        self::assertSame($before, db()->fetchAll('SELECT * FROM products ORDER BY id'));
    }
}
