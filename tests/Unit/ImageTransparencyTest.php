<?php
/**
 * 透明 PNG（2.0.5 媒体回归）：缩略图、WebP 副本、缩小超大图之后，透明的地方还是透明，不变成黑底或白底。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/image.php';

final class ImageTransparencyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yk-alpha-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->dir);
    }

    /** 1600×800 透明底，中间一块不透明红 */
    private function transparentPng(): string
    {
        $image = imagecreatetruecolor(1600, 800);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 600, 300, 1000, 500, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));
        $path = $this->dir . '/logo.png';
        imagepng($image, $path);
        return $path;
    }

    private static function alphaAt(string $path, string $ext, float $fx, float $fy): int
    {
        $image = $ext === 'webp' ? imagecreatefromwebp($path) : imagecreatefrompng($path);
        $x = (int) (imagesx($image) * $fx);
        $y = (int) (imagesy($image) * $fy);
        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    public function testThumbnailsKeepTransparency(): void
    {
        $path = $this->transparentPng();
        $thumbs = generateThumbnails($path, 'png');
        self::assertNotSame([], $thumbs);
        $files = glob($this->dir . '/logo_*.png') ?: [];
        self::assertNotSame([], $files, 'thumbnails were written');
        foreach ($files as $thumb) {
            self::assertGreaterThan(100, self::alphaAt($thumb, 'png', 0.05, 0.05), basename($thumb) . ': corner stays transparent');
        }
    }

    public function testWebpCopyKeepsTransparency(): void
    {
        if (!function_exists('imagewebp')) self::markTestSkipped('no WebP encoder');
        $path = $this->transparentPng();
        self::assertTrue(convertToWebp($path, $this->dir . '/logo.webp', 'png'));
        self::assertGreaterThan(100, self::alphaAt($this->dir . '/logo.webp', 'webp', 0.05, 0.05), 'corner stays transparent');
        self::assertSame(0, self::alphaAt($this->dir . '/logo.webp', 'webp', 0.5, 0.5), 'centre stays opaque');
    }

    public function testDownscaledPngKeepsTransparency(): void
    {
        $path = $this->transparentPng();
        self::assertTrue(downscaleImage($path, 'png', 800));
        self::assertSame(800, imagesx(imagecreatefrompng($path)));
        self::assertGreaterThan(100, self::alphaAt($path, 'png', 0.05, 0.05));
        self::assertSame(0, self::alphaAt($path, 'png', 0.5, 0.5));
    }
}
