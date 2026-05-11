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
        $sql = "SELECT * FROM {$this->tableName()} WHERE parent_id = ? AND status = 1";
        $params = [$parentId];
        if (isMultiLangEnabled('product_categories')) {
            $sql .= " AND lang = ?";
            $params[] = siteLang();
        }
        $sql .= " ORDER BY {$this->defaultOrder}";
        $items = db()->fetchAll($sql, $params);
        foreach ($items as &$item) {
            $item['children'] = $this->getTree((int) $item['id']);
        }
        unset($item);
        return $items;
    }

    /**
     * 获取带缩进的平面选项
     *
     * @param int    $parentId 父分类 id，递归时使用
     * @param int    $level    当前层级（缩进用）
     * @param string $lang     仅返回该语言的分类；传 '' 则不过滤 lang
     */
    public function getFlatOptions(int $parentId = 0, int $level = 0, string $lang = ''): array
    {
        $result = [];
        $cond = ['parent_id' => $parentId];
        if ($lang !== '') $cond['lang'] = $lang;
        $items = $this->where($cond);
        foreach ($items as $item) {
            $item['_level'] = $level;
            $item['_prefix'] = str_repeat('　', $level);
            $result[] = $item;
            $children = $this->getFlatOptions((int) $item['id'], $level + 1, $lang);
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
        $sql = "SELECT * FROM {$this->tableName()} WHERE parent_id = 0 AND status = 1";
        $params = [];
        if (isMultiLangEnabled('product_categories')) {
            $sql .= " AND lang = ?";
            $params[] = siteLang();
        }
        $sql .= " ORDER BY {$this->defaultOrder} LIMIT ?";
        $params[] = $limit;
        return db()->fetchAll($sql, $params);
    }
}
