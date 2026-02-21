<?php
declare(strict_types=1);

class TimelineModel extends Model
{
    protected string $table = 'timelines';
    protected string $defaultOrder = 'sort_order DESC, year DESC, month DESC';

    /**
     * 获取前台时间线列表
     */
    public function getActive(): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE status = 1 ORDER BY {$this->defaultOrder}"
        );
    }

    /**
     * 批量更新排序（拖拽排序）
     */
    public function updateSort(array $ids): void
    {
        $total = count($ids);
        foreach ($ids as $index => $id) {
            db()->update($this->table, ['sort_order' => $total - $index], 'id = ?', [(int) $id]);
        }
    }
}
