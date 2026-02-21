<?php
declare(strict_types=1);

class ArticleModel extends Model
{
    protected string $table = 'articles';
    protected string $defaultOrder = 'is_top DESC, publish_time DESC, id DESC';

    /**
     * 获取文章列表
     */
    public function getList(int $categoryId = 0, int $limit = 10, int $offset = 0, array $filters = []): array
    {
        $where = ['a.status = 1'];
        $params = [];

        if ($categoryId > 0) {
            $includeChildren = $filters['include_children'] ?? true;
            $catIds = $includeChildren ? articleCategoryModel()->getChildIds($categoryId) : [$categoryId];
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $where[] = "a.category_id IN ({$placeholders})";
            $params = array_merge($params, $catIds);
        }

        if (!empty($filters['keyword'])) {
            $where[] = '(a.title LIKE ? OR a.summary LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['is_recommend'])) {
            $where[] = 'a.is_recommend = 1';
        }
        if (!empty($filters['is_top'])) {
            $where[] = 'a.is_top = 1';
        }

        $whereSQL = implode(' AND ', $where);
        return db()->fetchAll(
            "SELECT a.*, ac.name as category_name, ac.slug as category_slug
             FROM {$this->tableName()} a
             LEFT JOIN " . DB_PREFIX . "article_categories ac ON a.category_id = ac.id
             WHERE {$whereSQL}
             ORDER BY {$this->defaultOrder}
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /**
     * 获取文章数量
     */
    public function getCount(int $categoryId = 0, array $filters = []): int
    {
        $where = ['status = 1'];
        $params = [];

        if ($categoryId > 0) {
            $includeChildren = $filters['include_children'] ?? true;
            $catIds = $includeChildren ? articleCategoryModel()->getChildIds($categoryId) : [$categoryId];
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $where[] = "category_id IN ({$placeholders})";
            $params = array_merge($params, $catIds);
        }

        if (!empty($filters['keyword'])) {
            $where[] = '(title LIKE ? OR summary LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = implode(' AND ', $where);
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE {$whereSQL}",
            $params
        );
    }

    /**
     * 后台文章列表（支持按分类/状态/关键字筛选，JOIN 分类名）
     */
    public function getAdminList(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'a.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'a.status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(a.title LIKE ? OR a.summary LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} a {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT a.*, c.name as category_name
             FROM {$this->tableName()} a
             LEFT JOIN " . DB_PREFIX . "article_categories c ON a.category_id = c.id
             {$whereSQL} ORDER BY a.is_top DESC, a.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 获取详情（JOIN 分类）
     */
    public function getDetail(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT a.*, ac.name as category_name, ac.slug as category_slug
             FROM {$this->tableName()} a
             LEFT JOIN " . DB_PREFIX . "article_categories ac ON a.category_id = ac.id
             WHERE a.id = ?",
            [$id]
        );
    }

    /**
     * 按 slug 查找（JOIN 分类）
     */
    public function findBySlug(string $slug): ?array
    {
        return db()->fetchOne(
            "SELECT a.*, ac.name as category_name, ac.slug as category_slug
             FROM {$this->tableName()} a
             LEFT JOIN " . DB_PREFIX . "article_categories ac ON a.category_id = ac.id
             WHERE a.slug = ? AND a.status = 1",
            [$slug]
        );
    }

    /**
     * 获取已发布的文章（前台用，JOIN 分类信息）
     */
    public function getPublished(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT a.*, c.name as category_name, c.slug as category_slug
             FROM {$this->tableName()} a
             LEFT JOIN " . DB_PREFIX . "article_categories c ON a.category_id = c.id
             WHERE a.id = ? AND a.status = 1",
            [$id]
        );
    }

    /**
     * 上一篇（按发布时间倒序的下一篇 = 时间更新的文章）
     */
    public function getPrev(int $categoryId, int $publishTime, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT id, title FROM {$this->tableName()} WHERE status = 1 AND category_id = ? AND (publish_time > ? OR (publish_time = ? AND id > ?)) ORDER BY publish_time ASC, id ASC LIMIT 1",
            [$categoryId, $publishTime, $publishTime, $currentId]
        );
    }

    /**
     * 下一篇（按发布时间倒序的前一篇 = 时间更旧的文章）
     */
    public function getNext(int $categoryId, int $publishTime, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT id, title FROM {$this->tableName()} WHERE status = 1 AND category_id = ? AND (publish_time < ? OR (publish_time = ? AND id < ?)) ORDER BY publish_time DESC, id DESC LIMIT 1",
            [$categoryId, $publishTime, $publishTime, $currentId]
        );
    }

    /**
     * 相关文章
     */
    public function getRelated(int $categoryId, int $excludeId, int $limit = 5): array
    {
        return db()->fetchAll(
            "SELECT id, title, cover, publish_time FROM {$this->tableName()} WHERE category_id = ? AND status = 1 AND id != ? ORDER BY publish_time DESC LIMIT ?",
            [$categoryId, $excludeId, $limit]
        );
    }

    /**
     * 按多个分类 ID 获取文章
     */
    public function getByCategoryIds(array $catIds, int $limit = 6): array
    {
        if (empty($catIds)) return [];
        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        return db()->fetchAll(
            "SELECT a.*, c.name as category_name, c.slug as category_slug
             FROM {$this->tableName()} a
             LEFT JOIN " . DB_PREFIX . "article_categories c ON a.category_id = c.id
             WHERE a.category_id IN ({$placeholders}) AND a.status = 1
             ORDER BY {$this->defaultOrder} LIMIT ?",
            array_merge($catIds, [$limit])
        );
    }

    /**
     * 增加浏览量
     */
    public function incrementViews(int $id): int
    {
        return $this->increment($id, 'views');
    }
}
