<?php
declare(strict_types=1);

class LinkModel extends Model
{
    protected string $table = 'links';
    protected string $defaultOrder = 'sort_order ASC, id DESC';

    /**
     * 获取前台有效链接
     */
    public function getActive(int $limit = 20): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE status = 1 ORDER BY sort_order DESC, id DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * 批量更新排序
     */
    public function updateSort(array $ids): void
    {
        foreach ($ids as $index => $id) {
            db()->update($this->table, ['sort_order' => $index], 'id = ?', [(int) $id]);
        }
    }
}
