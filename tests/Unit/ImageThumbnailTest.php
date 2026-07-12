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
