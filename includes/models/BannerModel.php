<?php
declare(strict_types=1);

class BannerModel extends Model
{
    protected string $table = 'banners';
    protected string $defaultOrder = 'sort_order ASC, id DESC';

    /**
     * 按位置获取有效轮播图（含时间过滤）
     */
    public function getByPosition(string $position, int $limit = 10): array
    {
        $now = time();
        $sql = "SELECT * FROM {$this->tableName()} WHERE status = 1 AND position = ? AND (start_time = 0 OR start_time <= ?) AND (end_time = 0 OR end_time >= ?)";
        $params = [$position, $now, $now];
        if (isMultiLangEnabled('banners')) {
            $sql .= " AND lang = ?";
            $params[] = siteLang();
        }
        $sql .= " ORDER BY sort_order ASC, id DESC LIMIT ?";
        $params[] = $limit;
        return db()->fetchAll($sql, $params);
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
