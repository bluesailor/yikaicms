<?php

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

final class ProductModelTest extends TestCase
{
    private \ProductModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new \ProductModel();
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE product_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL
            )',
            'CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER DEFAULT 0,
                title TEXT NOT NULL,
                summary TEXT DEFAULT \'\',
                model TEXT DEFAULT \'\',
                status INTEGER DEFAULT 1,
                is_recommend INTEGER DEFAULT 0,
                is_hot INTEGER DEFAULT 0,
                is_new INTEGER DEFAULT 0,
                is_top INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                price REAL DEFAULT 0,
                views INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT 0,
                deleted_at INTEGER NULL
            )',
        ];
    }

    public function testRecommendedFirstFillsRemainingLimitWithPublishedProducts(): void
    {
        foreach ([1, 1, 1, 1, 0, 0] as $index => $recommended) {
            $this->insertRow('products', [
                'title' => 'Product ' . ($index + 1),
                'is_recommend' => $recommended,
                'sort_order' => $index + 1,
            ]);
        }

        $items = $this->model->getList(0, 8, 0, ['sort' => 'recommend_first']);

        $this->assertCount(6, $items);
        $this->assertSame([1, 1, 1, 1, 0, 0], array_map(
            static fn (array $item): int => (int) $item['is_recommend'],
            $items
        ));
    }
}
