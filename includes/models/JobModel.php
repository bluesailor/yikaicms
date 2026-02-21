<?php
declare(strict_types=1);

class JobModel extends Model
{
    protected string $table = 'jobs';
    protected string $defaultOrder = 'is_top DESC, sort_order DESC, id DESC';

    /**
     * 获取招聘列表（分页+筛选）
     */
    public function getList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(title LIKE ? OR location LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} {$whereSQL}
             ORDER BY {$this->defaultOrder}
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 增加浏览量
     */
    public function incrementViews(int $id): int
    {
        return $this->increment($id, 'views');
    }
}
