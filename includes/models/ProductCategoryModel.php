<?php
declare(strict_types=1);

class ProductCategoryModel extends Model
{
    protected string $table = 'product_categories';
    protected string $defaultOrder = 'sort_order ASC, id ASC';

    /**
     * 按 slug 查找
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * 递归获取分类树
     */
    public function getTree(int $parentId = 0): array
    {
        $items = $this->where(['parent_id' => $parentId, 'status' => 1]);
        foreach ($items as &$item) {
            $item['children'] = $this->getTree((int) $item['id']);
        }
        unset($item);
        return $items;
    }

    /**
     * 获取带缩进的平面选项
     */
    public function getFlatOptions(int $parentId = 0, int $level = 0): array
    {
        $result = [];
        $items = $this->where(['parent_id' => $parentId]);
        foreach ($items as $item) {
            $item['_level'] = $level;
            $item['_prefix'] = str_repeat('　', $level);
            $result[] = $item;
            $children = $this->getFlatOptions((int) $item['id'], $level + 1);
            $result = array_merge($result, $children);
        }
        return $result;
    }

    /**
     * 递归获取所有子分类 ID（含自身）
     */
    public function getChildIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $children = db()->fetchAll(
            "SELECT id FROM {$this->tableName()} WHERE parent_id = ?",
            [$categoryId]
        );
        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getChildIds((int) $child['id']));
        }
        return $ids;
    }

    /**
     * 获取顶级分类
     */
    public function getTopLevel(int $limit = 6): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE parent_id = 0 AND status = 1 ORDER BY {$this->defaultOrder} LIMIT ?",
            [$limit]
        );
    }
}
