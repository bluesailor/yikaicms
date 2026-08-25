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
    private static function pngWithDimensions(int $width, int $height): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png);
        $png = substr_replace($png, pack('N', $width), 16, 4);
        return substr_replace($png, pack('N', $height), 20, 4);
    }

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

    public function testResponsiveImageAttributesUseSameRatioLocalCandidates(): void
    {
        $name = 'responsive-image-' . getmypid();
        $directory = ROOT_PATH . '/storage/cache/' . $name;
        $url = '/storage/cache/' . $name . '/photo.png';
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/photo.png', self::pngWithDimensions(1_600, 900));
        file_put_contents($directory . '/photo_medium.png', self::pngWithDimensions(800, 450));

        try {
            $image = responsiveImageData($url);
            $mediumUrl = '/storage/cache/' . $name . '/photo_medium.png';
            $this->assertSame($mediumUrl, $image['src']);
            $this->assertSame(800, $image['width']);
            $this->assertSame(450, $image['height']);
            $this->assertStringContainsString($mediumUrl . ' 800w', $image['srcset']);
            $this->assertStringContainsString('/storage/cache/' . $name . '/photo.png 1600w', $image['srcset']);

            $attributes = responsiveImageAttributes('/storage/cache/' . $name . '/photo.png', 'medium', '50vw');
            $this->assertStringContainsString('sizes="50vw"', $attributes);
            $this->assertStringContainsString('width="800" height="450"', $attributes);
        } finally {
            @unlink($directory . '/photo_medium.png');
            @unlink($directory . '/photo.png');
            @rmdir($directory);
        }
    }

    public function testResponsiveImageDoesNotMixDifferentCropRatios(): void
    {
        $name = 'responsive-crop-' . getmypid();
        $directory = ROOT_PATH . '/storage/cache/' . $name;
        $url = '/storage/cache/' . $name . '/photo.png';
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/photo.png', self::pngWithDimensions(1_600, 900));
        file_put_contents($directory . '/photo_thumb.png', self::pngWithDimensions(300, 300));

        try {
            $image = responsiveImageData($url, 'thumb');
            $this->assertSame('/storage/cache/' . $name . '/photo_thumb.png', $image['src']);
            $this->assertSame('', $image['srcset']);
            $this->assertSame(300, $image['width']);
            $this->assertSame(300, $image['height']);
        } finally {
            @unlink($directory . '/photo_thumb.png');
            @unlink($directory . '/photo.png');
            @rmdir($directory);
        }
    }

    public function testResponsiveImageKeepsExternalUrlsFilesystemFree(): void
    {
        $attributes = responsiveImageAttributes('https://example.com/photo.jpg?a=1&b=2');
        $this->assertSame('src="https://example.com/photo.jpg?a=1&amp;b=2"', $attributes);
        $this->assertSame([0, 0], _localImageDimensions('/../config/config.php'));
    }

    public function testCoreCardTemplatesUseResponsiveImageAttributes(): void
    {
        $templates = [
            'themes/default/partials/product-card.php',
            'themes/default/partials/article-card.php',
            'themes/default/partials/article-grid-card.php',
            'themes/default/partials/case-card.php',
            'includes/partials/product-card.php',
            'includes/partials/article-card.php',
            'includes/partials/case-card.php',
        ];

        foreach ($templates as $template) {
            $source = file_get_contents(ROOT_PATH . '/' . $template);
            $this->assertIsString($source);
            $this->assertStringContainsString('responsiveImageAttributes(', $source, $template);
            $this->assertStringContainsString('decoding="async"', $source, $template);
        }
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
