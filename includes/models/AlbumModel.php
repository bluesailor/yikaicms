<?php
declare(strict_types=1);

class AlbumModel extends Model
{
    protected string $table = 'albums';
    protected string $defaultOrder = 'sort_order DESC, id DESC';

    /**
     * 获取前台有效相册
     */
    public function getActive(): array
    {
        return $this->where(['status' => 1]);
    }

    /**
     * 更新相册照片数量
     */
    public function updatePhotoCount(int $albumId): void
    {
        $count = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "album_photos WHERE album_id = ?",
            [$albumId]
        );
        db()->update($this->table, [
            'photo_count' => $count,
            'updated_at'  => time(),
        ], 'id = ?', [$albumId]);
    }

    /**
     * 设置封面图
     */
    public function setCover(int $albumId, string $image): int
    {
        return $this->updateById($albumId, [
            'cover'      => $image,
            'updated_at' => time(),
        ]);
    }
}
