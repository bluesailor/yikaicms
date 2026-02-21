<?php
declare(strict_types=1);

class FormModel extends Model
{
    protected string $table = 'forms';
    protected string $defaultOrder = 'id DESC';

    /**
     * 获取表单列表（分页+筛选）
     */
    public function getList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(name LIKE ? OR phone LIKE ? OR content LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
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
     * 更新跟进状态
     */
    public function updateFollow(int $id, int $status, string $adminName, string $note = ''): int
    {
        return $this->updateById($id, [
            'status'       => $status,
            'follow_admin' => $adminName,
            'follow_note'  => $note,
        ]);
    }
}
