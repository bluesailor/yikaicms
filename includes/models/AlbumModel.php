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
     * 更新相册照片数量，并自动设置封面
     */
    public function updatePhotoCount(int $albumId): void
    {
        $count = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "album_photos WHERE album_id = ?",
            [$albumId]
        );

        $data = [
            'photo_count' => $count,
            'updated_at'  => time(),
        ];

        // 如果没有封面，用第一张图片
        $album = $this->find($albumId);
        if ($album && empty($album['cover']) && $count > 0) {
            $firstPhoto = db()->fetchOne(
                "SELECT image FROM " . DB_PREFIX . "album_photos WHERE album_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1",
                [$albumId]
            );
            if ($firstPhoto) {
                $data['cover'] = $firstPhoto['image'];
            }
        }

        db()->update($this->table, $data, 'id = ?', [$albumId]);
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
