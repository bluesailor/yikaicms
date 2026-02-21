<?php
declare(strict_types=1);

class DownloadModel extends Model
{
    protected string $table = 'downloads';
    protected string $defaultOrder = 'sort_order DESC, id DESC';

    /**
     * 获取下载列表（分页+筛选，JOIN 分类）
     */
    public function getList(int $categoryId = 0, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if ($categoryId > 0) {
            $where[] = 'd.category_id = ?';
            $params[] = $categoryId;
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'd.status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(d.title LIKE ? OR d.description LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} d {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT d.*, c.name as category_name
             FROM {$this->tableName()} d
             LEFT JOIN " . DB_PREFIX . "download_categories c ON d.category_id = c.id
             {$whereSQL}
             ORDER BY d.sort_order DESC, d.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 批量获取文件信息（用于删除前判断）
     */
    public function getFileInfoByIds(array $ids): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return db()->fetchAll(
            "SELECT id, file_url, is_external FROM {$this->tableName()} WHERE id IN ({$placeholders})",
            $ids
        );
    }

    /**
     * 增加下载次数
     */
    public function incrementDownloads(int $id): int
    {
        return $this->increment($id, 'download_count');
    }
}
