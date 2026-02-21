<?php
declare(strict_types=1);

class MediaModel extends Model
{
    protected string $table = 'media';
    protected string $defaultOrder = 'id DESC';

    /**
     * 获取媒体列表（分页+筛选）
     */
    public function getList(array $filters = [], int $limit = 24, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} {$whereSQL} ORDER BY {$this->defaultOrder} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 批量获取文件路径
     */
    public function getPathsByIds(array $ids): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return db()->fetchAll(
            "SELECT id, path FROM {$this->tableName()} WHERE id IN ({$placeholders})",
            $ids
        );
    }
}
