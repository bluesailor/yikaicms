<?php
/**
 * 相册批量上传：每个文件必须过 uploadFile() 的内容校验，未通过的逐个报回。
 *
 * uploadFile() 依赖完整应用上下文（functions.php、上传目录），这里注入替身上传器，
 * 验证的是「谁被放行、谁被拒绝、拒绝原因能否带回界面」；两处入口不再手写
 * move_uploaded_file 由源码契约锁定。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/admin/includes/album_upload.php';

final class AlbumPhotoUploadTest extends TestCase
{
    protected function setUp(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS album_photos (id INTEGER PRIMARY KEY AUTOINCREMENT, album_id INTEGER, title TEXT, description TEXT, image TEXT, thumb TEXT, sort_order INTEGER DEFAULT 0, status INTEGER DEFAULT 1, created_at INTEGER DEFAULT 0)');
        db()->execute('DELETE FROM album_photos');
    }

    /** @return array<string,mixed> */
    private static function field(array $names): array
    {
        return [
            'name' => $names,
            'type' => array_fill(0, count($names), 'image/jpeg'),
            'tmp_name' => array_map(static fn(string $name): string => '/tmp/' . $name, $names),
            'error' => array_fill(0, count($names), UPLOAD_ERR_OK),
            'size' => array_fill(0, count($names), 1024),
        ];
    }

    public function testEveryFileIsValidatedAndRejectionsAreReportedIndividually(): void
    {
        $calls = [];
        $upload = static function (array $file, string $type) use (&$calls): array {
            $calls[] = [$file['name'], $type];
            return $file['name'] === 'fake.jpg'
                ? ['error' => '文件内容与扩展名不匹配']
                : ['url' => '/uploads/albums/202609/' . $file['name']];
        };

        $result = albumStoreUploadedPhotos(7, self::field(['good.jpg', 'fake.jpg', 'shell.php', 'vector.svg', 'second.webp']), $upload);

        // 非位图扩展名在调用上传器之前就被拒；其余每个都交给上传器、落在 albums 目录
        self::assertSame([['good.jpg', 'albums'], ['fake.jpg', 'albums'], ['second.webp', 'albums']], $calls);
        self::assertSame(['good', 'second'], array_column($result['uploaded'], 'title'));
        self::assertSame(['fake.jpg', 'shell.php', 'vector.svg'], array_column($result['rejected'], 'name'));
        self::assertSame('文件内容与扩展名不匹配', $result['rejected'][0]['error']);

        $rows = db()->fetchAll('SELECT album_id, title, image, sort_order FROM album_photos ORDER BY id');
        self::assertCount(2, $rows);
        self::assertSame('/uploads/albums/202609/good.jpg', $rows[0]['image']);
        self::assertSame([1, 2], array_map('intval', array_column($rows, 'sort_order')));
    }

    public function testFailedPhpUploadSlotsAreRejectedAndEmptySlotsIgnored(): void
    {
        $field = self::field(['big.jpg', '']);
        $field['error'] = [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_NO_FILE];
        $result = albumStoreUploadedPhotos(7, $field, static fn(array $file): array => $file['error'] === UPLOAD_ERR_OK
            ? ['url' => '/uploads/albums/x.jpg']
            : ['error' => '上传失败：' . $file['error']]);

        self::assertSame([], $result['uploaded']);
        self::assertSame([['name' => 'big.jpg', 'error' => '上传失败：1']], $result['rejected']);
    }

    public function testAlbumEntryPointsNoLongerMoveUploadedFilesThemselves(): void
    {
        foreach (['admin/album.php', 'admin/album_photos.php'] as $page) {
            $source = (string) file_get_contents(ROOT_PATH . '/' . $page);
            self::assertStringNotContainsString('move_uploaded_file', $source, $page . ' 必须走 albumStoreUploadedPhotos / uploadFile');
            self::assertStringContainsString('albumStoreUploadedPhotos(', $source, $page);
        }
        self::assertStringContainsString("'images', 'albums' => UPLOAD_IMAGE_TYPES", (string) file_get_contents(ROOT_PATH . '/includes/functions.php'));
    }
}
