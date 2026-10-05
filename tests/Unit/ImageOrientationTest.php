<?php
/**
 * 手机照片的 EXIF 方向（2.0.5 媒体回归）：缩略图、WebP、缩小超大图、图片编辑读图时都按方向转正。
 * 期望值按 EXIF 规范对第 0 行 / 第 0 列的定义直接写出存储位置，不复用被测代码的变换。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/image.php';

final class ImageOrientationTest extends TestCase
{
    /** 显示时 40×20、左上角 10×5 红块；各方向下红块在存储图里的位置（EXIF 规范的第 0 行 / 第 0 列含义） */
    public static function orientations(): array
    {
        return [
            '1 normal' => [1, 'tl'], '2 mirror' => [2, 'tr'], '3 rotate 180' => [3, 'br'], '4 flip' => [4, 'bl'],
            '5 transpose' => [5, 'tl'], '6 rotate cw' => [6, 'bl'], '7 transverse' => [7, 'br'], '8 rotate ccw' => [8, 'tr'],
        ];
    }

    #[DataProvider('orientations')]
    public function testJpegIsLoadedUpright(int $orientation, string $corner): void
    {
        $path = self::storedJpeg($orientation, $corner);
        try {
            self::assertSame($orientation, imageExifOrientation($path, 'jpg'));
            self::assertSame([40, 20], imageUprightSize($path, 'jpg'));
            $image = imageLoadUpright($path, 'jpg');
            self::assertInstanceOf(\GdImage::class, $image);
            self::assertSame([40, 20], [imagesx($image), imagesy($image)]);
            self::assertTrue(self::isRed($image, 2, 1), 'red stays at the top-left');
            foreach ([[37, 1], [2, 18], [37, 18]] as [$x, $y]) {
                self::assertFalse(self::isRed($image, $x, $y), "($x,$y) is not red");
            }
        } finally {
            @unlink($path);
        }
    }

    public function testThumbnailsAndDownscaleUseTheUprightImage(): void
    {
        $path = self::storedJpeg(6, 'bl', 1600);   // 竖拍：存储 800×1600，显示 1600×800
        try {
            self::assertTrue(downscaleImage($path, 'jpg', 1200, 90));
            $size = getimagesize($path);
            self::assertSame([1200, 600], [$size[0], $size[1]], '缩小后写回的原图是转正的（EXIF 丢了也不横）');
            $image = imagecreatefromjpeg($path);
            self::assertTrue(self::isRed($image, 20, 10));
            self::assertSame(1, imageExifOrientation($path, 'jpg'), '写回的文件不再带方向标记');
        } finally {
            @unlink($path);
        }
    }

    public function testNonJpegAndMissingFilesAreUntouched(): void
    {
        self::assertSame(1, imageExifOrientation(__FILE__, 'png'));
        self::assertSame([0, 0], imageUprightSize(__DIR__ . '/missing.jpg', 'jpg'));
        self::assertFalse(imageLoadUpright(__DIR__ . '/missing.jpg', 'jpg'));
    }

    private static function isRed(\GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);
        return (($rgb >> 16) & 0xFF) > 200 && (($rgb >> 8) & 0xFF) < 80 && ($rgb & 0xFF) < 80;
    }

    /** 造一张存储方向为 $orientation 的 JPEG：红块放在存储图的 $corner，再插入 EXIF Orientation */
    private static function storedJpeg(int $orientation, string $corner, int $displayWidth = 40): string
    {
        [$w, $h] = $orientation >= 5 ? [$displayWidth / 2, $displayWidth] : [$displayWidth, $displayWidth / 2];
        [$bw, $bh] = $orientation >= 5 ? [$displayWidth / 8, $displayWidth / 4] : [$displayWidth / 4, $displayWidth / 8];
        $image = imagecreatetruecolor((int) $w, (int) $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        $x = str_ends_with($corner, 'r') ? $w - $bw : 0;
        $y = str_starts_with($corner, 'b') ? $h - $bh : 0;
        imagefilledrectangle($image, (int) $x, (int) $y, (int) ($x + $bw - 1), (int) ($y + $bh - 1), (int) imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();
        // APP1 Exif：TIFF 小端头 + IFD0 一项 Orientation(0x0112, SHORT)
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvV', 0x0112, 3, 1) . pack('vv', $orientation, 0) . pack('V', 0);
        $payload = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $path = tempnam(sys_get_temp_dir(), 'ykexif') . '.jpg';
        file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
        return $path;
    }
}
