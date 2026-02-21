<?php
declare(strict_types=1);

class AdminLogModel extends Model
{
    protected string $table = 'admin_logs';
    protected string $defaultOrder = 'id DESC';

    /**
     * 记录日志
     */
    public function log(array $data): int|string
    {
        return $this->create($data);
    }

    /**
     * 清理指定日期前的日志
     */
    public function clearBefore(int $timestamp): int
    {
        return db()->execute(
            "DELETE FROM {$this->tableName()} WHERE created_at < ?",
            [$timestamp]
        );
    }

    /**
     * 获取所有模块列表（去重）
     */
    public function getModules(): array
    {
        return db()->fetchAll(
            "SELECT DISTINCT module FROM {$this->tableName()} ORDER BY module"
        );
    }

    /**
     * 搜索日志（分页）
     */
    public function search(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['module'])) {
            $where[] = 'module = ?';
            $params[] = $filters['module'];
        }
        if (!empty($filters['admin_name'])) {
            $where[] = 'admin_name LIKE ?';
            $params[] = '%' . $filters['admin_name'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = strtotime($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = strtotime($filters['date_to'] . ' 23:59:59');
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
}
