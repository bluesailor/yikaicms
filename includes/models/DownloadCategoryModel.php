<?php
declare(strict_types=1);

class DownloadCategoryModel extends Model
{
    protected string $table = 'download_categories';
    protected string $defaultOrder = 'sort_order DESC, id ASC';

    /**
     * 获取有效分类
     */
    public function getActive(): array
    {
        return $this->where(['status' => 1], 'sort_order ASC, id ASC');
    }

    /** 按 slug 查分类（伪静态路由用） */
    public function findBySlug(string $slug): ?array
    {
        if ($slug === '') return null;
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE slug = ? LIMIT 1",
            [$slug]
        ) ?: null;
    }

    /**
     * 把前端 cat 参数解析成分类 id：
     *   - 纯数字 → 直接当 id（兼容旧 ?cat=1）
     *   - 其它   → 当 slug 查（伪静态 /download/{slug}.html）
     * 解析不到返回 0（= 全部）。
     */
    public function resolveId(string $cat): int
    {
        $cat = trim($cat);
        if ($cat === '') return 0;
        if (ctype_digit($cat)) return (int) $cat;
        $row = $this->findBySlug($cat);
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * 获取分类列表（含文件数量统计）
     */
    public function getAllWithCount(): array
    {
        return db()->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM " . DB_PREFIX . "downloads d WHERE d.category_id = c.id) as file_count
             FROM {$this->tableName()} c
             ORDER BY c.sort_order DESC, c.id ASC"
        );
    }

    /**
     * 检查分类下是否有文件
     */
    public function hasFiles(int $categoryId): bool
    {
        $count = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "downloads WHERE category_id = ?",
            [$categoryId]
        );
        return $count > 0;
    }
}
