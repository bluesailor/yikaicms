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
