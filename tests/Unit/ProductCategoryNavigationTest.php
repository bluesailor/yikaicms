<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/models/ProductCategoryModel.php';

final class ProductCategoryNavigationTest extends TestCase
{
    public function testDeepActiveStateIsSharedWithoutChangingCategoryOrderOrData(): void
    {
        $model = new class extends \ProductCategoryModel {
            public function getTree(int $parentId = 0): array
            {
                return [
                    ['id' => 1, 'name' => 'Parent', 'children' => [
                        ['id' => 2, 'children' => [['id' => 3]]],
                    ]],
                    ['id' => 4],
                ];
            }
        };
        $tree = $model->getNavigationTree(3);
        $this->assertSame([1, 4], array_column($tree, 'id'));
        $this->assertSame('Parent', $tree[0]['name']);
        $this->assertFalse($tree[0]['is_active']);
        $this->assertTrue($tree[0]['has_active_child']);
        $this->assertTrue($tree[0]['children'][0]['has_active_child']);
        $this->assertTrue($tree[0]['children'][0]['children'][0]['is_active']);
        $this->assertFalse($tree[1]['has_active_child']);
        $this->assertSame([], $tree[1]['children']);
        $this->assertFalse($model->getNavigationTree()[0]['has_active_child']);
        $this->assertTrue($model->getNavigationTree(1)[0]['is_active']);
    }
}
