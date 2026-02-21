<?php
declare(strict_types=1);

class AlbumPhotoModel extends Model
{
    protected string $table = 'album_photos';
    protected string $defaultOrder = 'sort_order DESC, id DESC';

    /**
     * 获取相册下的照片
     */
    public function getByAlbum(int $albumId): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE album_id = ? ORDER BY {$this->defaultOrder}",
            [$albumId]
        );
    }

    /**
     * 获取最大排序值
     */
    public function getMaxSort(int $albumId): int
    {
        return (int) db()->fetchColumn(
            "SELECT MAX(sort_order) FROM {$this->tableName()} WHERE album_id = ?",
            [$albumId]
        );
    }

    /**
     * 更新排序（含相册ID校验）
     */
    public function updateSort(int $photoId, int $albumId, int $sortOrder): int
    {
        return db()->execute(
            "UPDATE {$this->tableName()} SET sort_order = ? WHERE id = ? AND album_id = ?",
            [$sortOrder, $photoId, $albumId]
        );
    }

    /**
     * 删除相册下所有照片
     */
    public function deleteByAlbum(int $albumId): int
    {
        return db()->execute(
            "DELETE FROM {$this->tableName()} WHERE album_id = ?",
            [$albumId]
        );
    }

    /**
     * 批量删除（含相册ID校验）
     */
    public function deleteByIds(array $ids, int $albumId = 0): int
    {
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $sql = "DELETE FROM {$this->tableName()} WHERE id IN ({$placeholders})";
        if ($albumId > 0) {
            $sql .= ' AND album_id = ?';
            $params[] = $albumId;
        }
        return db()->execute($sql, $params);
    }

    /**
     * 批量获取照片信息（含相册ID校验）
     */
    public function getByIdsAndAlbum(array $ids, int $albumId): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$albumId]);
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE id IN ({$placeholders}) AND album_id = ?",
            $params
        );
    }
}
