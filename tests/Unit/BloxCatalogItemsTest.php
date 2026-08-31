<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxCatalogItems.php';
require_once ROOT_PATH . '/includes/UrlPolicy.php';

final class BloxCatalogItemsTest extends TestCase
{
    protected function schemaSql(): array
    {
        $records = 'id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, summary TEXT, cover TEXT,
            status INTEGER DEFAULT 1, is_top INTEGER DEFAULT 0, deleted_at INTEGER DEFAULT NULL';
        return [
            'CREATE TABLE channels (id INTEGER PRIMARY KEY, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT, type TEXT)',
            'CREATE TABLE product_categories (id INTEGER PRIMARY KEY, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT)',
            "CREATE TABLE contents ($records, channel_id INTEGER, type TEXT DEFAULT 'article', publish_time INTEGER DEFAULT 0)",
            "CREATE TABLE products ($records, category_id INTEGER, model TEXT, sort_order INTEGER DEFAULT 0)",
        ];
    }

    public function testArticlesAreBoundedScopedAndNeverReturnDraftsOrOtherContentTypes(): void
    {
        $this->insertRow('channels', ['id' => 1, 'type' => 'list']);
        $this->insertRow('channels', ['id' => 2, 'parent_id' => 1, 'type' => 'list']);
        $this->insertRow('channels', ['id' => 3, 'type' => 'list']);
        for ($i = 0; $i < 8; $i++) {
            $this->insertRow('contents', ['channel_id' => 2, 'title' => 'Article ' . $i,
                'cover' => 'javascript:alert(1)']);
        }
        foreach ([['status' => 0], ['deleted_at' => 123], ['type' => 'case'], ['channel_id' => 3]] as $extra) {
            $this->insertRow('contents', array_merge(['channel_id' => 2, 'title' => 'Excluded'], $extra));
        }
        $channel = ['id' => 1, 'type' => 'list', 'lang' => 'en'];
        $first = \BloxCatalogItems::read($channel, '', 0);
        self::assertCount(6, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertSame(1, $first['page']);
        self::assertSame(['id', 'title', 'cover'], array_keys($first['items'][0]));
        self::assertSame('', $first['items'][0]['cover']);
        $second = \BloxCatalogItems::read($channel, '', 2);
        self::assertCount(2, $second['items']);
        self::assertFalse($second['has_more']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        self::assertSame([], \BloxCatalogItems::read($channel, "' OR 1=1 --", 1)['items']);
        self::assertCount(1, \BloxCatalogItems::read($channel, 'Article 3', 1)['items']);
        self::assertSame(1000, \BloxCatalogItems::read($channel, '', PHP_INT_MAX)['page']);
        self::assertFalse(\BloxCatalogItems::read($channel, '', PHP_INT_MAX)['has_more']);
    }

    public function testProductSubChannelUsesItsCategoryAndTopLevelUsesAll(): void
    {
        $this->insertRow('product_categories', ['id' => 1]);
        $this->insertRow('product_categories', ['id' => 2]);
        $this->insertRow('products', ['category_id' => 1, 'title' => 'One']);
        $this->insertRow('products', ['category_id' => 2, 'title' => 'Two']);
        $this->insertRow('products', ['category_id' => 2, 'title' => 'Draft', 'status' => 0]);
        $this->insertRow('products', ['category_id' => 2, 'title' => 'Deleted', 'deleted_at' => 12]);
        self::assertCount(2, \BloxCatalogItems::read(['id' => 1, 'type' => 'product'], '', 1)['items']);
        self::assertSame(['Two'], array_column(\BloxCatalogItems::read(['id' => 2, 'parent_id' => 1, 'type' => 'product'], '', 1)['items'], 'title'));
    }

    public function testOtherPageTypesAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        \BloxCatalogItems::read(['id' => 1, 'type' => 'page'], '', 1);
    }
}
