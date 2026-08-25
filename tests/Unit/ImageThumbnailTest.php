<?php
/**
 * Tests for includes/image.php — 图像单元从 functions.php 抽出后的纯逻辑回归。
 *
 * 只测不依赖 GD / 真实文件的纯函数（URL 映射与回退）：thumbnail / webpUrl。
 * 缩略图不存在时应回退到原图 URL，尺寸未定义时原样返回。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/image.php';

final class ImageThumbnailTest extends TestCase
{
    public function testThumbnailSizesConstDefined(): void
    {
        $this->assertTrue(defined('THUMBNAIL_SIZES'));
        $this->assertArrayHasKey('thumb', THUMBNAIL_SIZES);
        $this->assertArrayHasKey('medium', THUMBNAIL_SIZES);
    }

    public function testImagePixelLimitHandlesBoundariesWithoutOverflow(): void
    {
        $this->assertTrue(imageDimensionsWithinPixelLimit(8_000, 5_000, 40_000_000));
        $this->assertFalse(imageDimensionsWithinPixelLimit(8_001, 5_000, 40_000_000));
        $this->assertFalse(imageDimensionsWithinPixelLimit(PHP_INT_MAX, PHP_INT_MAX, 40_000_000));
        $this->assertFalse(imageDimensionsWithinPixelLimit(0, 1, 40_000_000));
    }

    public function testConfiguredImagePixelLimitIsClampedToSafeBounds(): void
    {
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides']['upload_max_megapixels'] = 55;
            $this->assertSame(55, uploadMaxImageMegapixels());

            $GLOBALS['yikai_config_runtime_overrides']['upload_max_megapixels'] = 500;
            $this->assertSame(200, uploadMaxImageMegapixels());

            $GLOBALS['yikai_config_runtime_overrides']['upload_max_megapixels'] = 0;
            $this->assertSame(40, uploadMaxImageMegapixels());

            $GLOBALS['yikai_config_runtime_overrides']['upload_max_megapixels'] = [];
            $this->assertSame(40, uploadMaxImageMegapixels());
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
        }
    }

    public function testThumbnailEmptyReturnsEmpty(): void
    {
        $this->assertSame('', thumbnail(''));
        $this->assertSame('', thumbnail(null));
    }

    public function testThumbnailUnknownSizeReturnsOriginal(): void
    {
        $url = '/uploads/images/202602/img.jpg';
        $this->assertSame($url, thumbnail($url, 'no-such-size'));
    }

    public function testThumbnailFallsBackWhenFileMissing(): void
    {
        // 缩略图文件不存在（ROOT_PATH 下无此文件）→ 回退原图 URL
        $url = '/uploads/images/does-not-exist/img.jpg';
        $this->assertSame($url, thumbnail($url, 'thumb'));
    }

    public function testWebpUrlEmptyAndFallback(): void
    {
        $this->assertSame('', webpUrl(''));
        $url = '/uploads/images/x/pic.png';
        // 无对应 .webp 文件 → 返回原 URL
        $this->assertSame($url, webpUrl($url));
        // 非 jpg/png 扩展名不转换，原样返回
        $this->assertSame('/x/a.gif', webpUrl('/x/a.gif'));
    }
}
