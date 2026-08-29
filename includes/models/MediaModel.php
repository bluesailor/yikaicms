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
        $preferredMinWidth = max(0, min(10000, (int) ($filters['preferred_min_width'] ?? 0)));

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

        $order = $this->defaultOrder;
        if ($preferredMinWidth > 0 && (($filters['type'] ?? '') === 'image')) {
            $order = "CASE WHEN width >= {$preferredMinWidth} THEN 0 ELSE 1 END ASC, {$order}";
        }

        $items = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} {$whereSQL} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE id IN ({$placeholders})",
            $ids
        );
    }

    public function countImages(): int
    {
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE type = ?",
            ['image']
        );
    }

    /** @return list<array<string,mixed>> */
    public function getImageBatchAfterId(int $afterId, int $limit): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min(MediaOptimization::MAX_BATCH, $limit));

        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE type = ? AND id > ? ORDER BY id ASC LIMIT ?",
            ['image', $afterId, $limit]
        );
    }
}
