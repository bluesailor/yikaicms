<?php
/**
 * Yikai CMS - 图像处理（缩略图 / 等比缩放 / WebP）
 *
 * 从 includes/functions.php 抽出的内聚图像单元（P2-2 拆分上帝文件）。
 * 由 functions.php 原位 require，全局函数名不变，调用方无需改动。
 * 依赖：THUMBNAIL_SIZES（本文件定义）、ROOT_PATH、GD 扩展。
 */

declare(strict_types=1);

/**
 * 缩略图尺寸配置
 * thumb: 300x300 裁剪居中（用于列表卡片）
 * medium: 800x600 等比缩放（用于详情页侧栏）
 */
define('THUMBNAIL_SIZES', [
    'thumb'  => ['width' => 300, 'height' => 300, 'crop' => true],
    'medium' => ['width' => 800, 'height' => 600, 'crop' => false],
]);

/**
 * 上传图片允许的总像素数。限制在合理区间，防止被直接写入异常配置绕过。
 */
function uploadMaxImageMegapixels(): int
{
    $value = config('upload_max_megapixels', '40');
    $configured = is_numeric($value) ? (int) $value : 40;
    return $configured >= 1 ? min(200, $configured) : 40;
}

function imageDimensionsWithinPixelLimit(int $width, int $height, int $maxPixels): bool
{
    if ($width < 1 || $height < 1 || $maxPixels < 1) {
        return false;
    }

    // 用除法避免恶意超大尺寸在 32 位环境做乘法时溢出。
    return $height <= intdiv($maxPixels, $width);
}

/**
 * 为上传的图片生成缩略图
 */
function generateThumbnails(string $filepath, string $ext): array
{
    $supportedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $supportedExts) || !function_exists('imagecreatetruecolor')) {
        return [];
    }

    $srcImage = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($filepath),
        'png'         => @imagecreatefrompng($filepath),
        'gif'         => @imagecreatefromgif($filepath),
        'webp'        => @imagecreatefromwebp($filepath),
        default       => false,
    };

    if (!$srcImage) return [];

    $srcW = imagesx($srcImage);
    $srcH = imagesy($srcImage);
    $thumbs = [];

    foreach (THUMBNAIL_SIZES as $sizeName => $sizeConf) {
        $maxW = $sizeConf['width'];
        $maxH = $sizeConf['height'];
        $crop = $sizeConf['crop'];

        // 跳过比缩略图还小的原图
        if ($srcW <= $maxW && $srcH <= $maxH) {
            continue;
        }

        if ($crop) {
            // 裁剪模式：从中心裁剪
            $ratio = max($maxW / $srcW, $maxH / $srcH);
            $resW = (int)ceil($srcW * $ratio);
            $resH = (int)ceil($srcH * $ratio);
            $offsetX = (int)(($resW - $maxW) / 2 / $ratio);
            $offsetY = (int)(($resH - $maxH) / 2 / $ratio);
            $cropW = (int)($maxW / $ratio);
            $cropH = (int)($maxH / $ratio);

            $dstImage = imagecreatetruecolor($maxW, $maxH);
            _preserveTransparency($dstImage, $ext);
            imagecopyresampled($dstImage, $srcImage, 0, 0, $offsetX, $offsetY, $maxW, $maxH, $cropW, $cropH);
        } else {
            // 等比缩放
            $ratio = min($maxW / $srcW, $maxH / $srcH);
            $newW = (int)round($srcW * $ratio);
            $newH = (int)round($srcH * $ratio);

            $dstImage = imagecreatetruecolor($newW, $newH);
            _preserveTransparency($dstImage, $ext);
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        }

        // 生成缩略图文件路径
        $thumbPath = _thumbnailPath($filepath, $sizeName);
        _saveImage($dstImage, $thumbPath, $ext);
        unset($dstImage);

        $thumbs[$sizeName] = $thumbPath;
    }

    unset($srcImage);
    return $thumbs;
}

/**
 * 保持 PNG/GIF/WebP 透明度
 */
function _preserveTransparency($image, string $ext): void
{
    if (in_array($ext, ['png', 'gif', 'webp'])) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
    }
}

/**
 * 保存图片到文件
 */
function _saveImage($image, string $path, string $ext): void
{
    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($image, $path, 85),
        'png'         => imagepng($image, $path, 6),
        'gif'         => imagegif($image, $path),
        'webp'        => imagewebp($image, $path, 85),
    };
}

/**
 * 将原图等比缩小到最大宽度 $maxW（仅当原图更宽时），直接覆盖原文件。
 * 用于限制客户上传的超大图。$quality 用于 JPEG/WebP 重新编码（PNG 为无损，忽略）。
 * 返回是否实际进行了压缩。GIF/SVG 不处理（动图/矢量）。
 */
function downscaleImage(string $filepath, string $ext, int $maxW, int $quality = 85): bool
{
    if (!function_exists('imagecreatetruecolor') || $maxW <= 0) {
        return false;
    }
    $src = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($filepath),
        'png'         => @imagecreatefrompng($filepath),
        'webp'        => @imagecreatefromwebp($filepath),
        default       => false,
    };
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= $maxW) {            // 不需要缩
        unset($src);
        return false;
    }

    $newW = $maxW;
    $newH = max(1, (int) round($srcH * ($maxW / $srcW)));
    $dst  = imagecreatetruecolor($newW, $newH);
    _preserveTransparency($dst, $ext);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

    $quality = max(1, min(100, $quality));
    $ok = match ($ext) {
        'jpg', 'jpeg' => imagejpeg($dst, $filepath, $quality),
        'png'         => imagepng($dst, $filepath, 6),   // PNG 用压缩级别(0-9)，与质量无关
        'webp'        => imagewebp($dst, $filepath, $quality),
        default       => false,
    };

    unset($src, $dst);
    return (bool) $ok;
}

/**
 * 生成缩略图文件路径
 * 例: /uploads/images/202602/img_abc123.jpg → /uploads/images/202602/img_abc123_thumb.jpg
 */
function _thumbnailPath(string $filepath, string $sizeName): string
{
    $info = pathinfo($filepath);
    return $info['dirname'] . '/' . $info['filename'] . '_' . $sizeName . '.' . $info['extension'];
}

/**
 * 是否为远程/绝对 URL 或 data URI。
 * 这类地址没有对应的本地文件，绝不能与 ROOT_PATH 拼接去做 file_exists 检查
 * （否则会拼出 /www/wwwroot/site/https://... 之类的非法路径，在开启 open_basedir 的
 * 服务器上还会抛 Warning 并被注入到 <img src>）。
 */
function _isExternalUrl(string $url): bool
{
    return (bool) preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $url)  // http:// https:// // 协议相对
        || str_starts_with($url, 'data:');
}

/**
 * 获取缩略图URL
 * 用法: thumbnail('/uploads/images/202602/img.jpg', 'thumb')
 * 若缩略图不存在则返回原图URL
 */
function thumbnail(?string $url, string $size = 'thumb'): string
{
    if (empty($url)) return '';
    // 远程/绝对 URL 或 data URI：无本地缩略图，原样返回
    if (_isExternalUrl($url)) return $url;
    if (!isset(THUMBNAIL_SIZES[$size])) return $url;

    $info = pathinfo($url);
    $thumbUrl = $info['dirname'] . '/' . $info['filename'] . '_' . $size . '.' . ($info['extension'] ?? 'jpg');

    // 检查文件是否存在（@ 兜底：极端环境下也不让文件系统告警泄露到页面）
    $thumbPath = ROOT_PATH . $thumbUrl;
    if (@file_exists($thumbPath)) {
        return $thumbUrl;
    }

    return $url;
}

/**
 * 将图片转换为 WebP 格式
 */
function convertToWebp(string $srcPath, string $dstPath, string $srcExt, int $quality = 80): bool
{
    $srcImage = match ($srcExt) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($srcPath),
        'png'         => @imagecreatefrompng($srcPath),
        default       => false,
    };

    if (!$srcImage) return false;

    if ($srcExt === 'png') {
        imagepalettetotruecolor($srcImage);
        imagealphablending($srcImage, true);
        imagesavealpha($srcImage, true);
    }

    $result = imagewebp($srcImage, $dstPath, $quality);
    unset($srcImage);
    return $result;
}

/**
 * 获取图片的 WebP URL（如果存在）
 * 用法: webpUrl('/uploads/images/202602/img.jpg')
 */
function webpUrl(?string $url): string
{
    if (empty($url)) return '';
    // 远程/绝对 URL 或 data URI：无本地 webp，原样返回
    if (_isExternalUrl($url)) return $url;

    $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $url);
    if ($webp === $url) return $url;

    $webpPath = ROOT_PATH . $webp;
    if (@file_exists($webpPath)) {
        return $webp;
    }

    return $url;
}
