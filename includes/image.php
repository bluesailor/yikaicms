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
    if ($configured === 0) {
        return 0;
    }
    return $configured >= 1 ? min(200, $configured) : 40;
}

function imageDimensionsWithinPixelLimit(int $width, int $height, int $maxPixels): bool
{
    if ($width < 1 || $height < 1 || $maxPixels < 0) {
        return false;
    }
    if ($maxPixels === 0) {
        return true;
    }

    // 用除法避免恶意超大尺寸在 32 位环境做乘法时溢出。
    return $height <= intdiv($maxPixels, $width);
}

/**
 * JPEG 的 EXIF 方向（1–8）。读不到、不是 JPEG、没有 exif 扩展时为 1（不转）。
 * 手机竖拍的照片像素是横着存的，靠这个标记让看图软件转正；GD 读图不认它（2.0.5 媒体回归）。
 */
function imageExifOrientation(string $path, string $ext): int
{
    if (!in_array(strtolower($ext), ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
        return 1;
    }
    $exif = @exif_read_data($path, 'IFD0');
    $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
}

/**
 * 按 EXIF 方向把图像转正（EXIF 规范：2 水平镜像、3 转 180°、4 垂直镜像、5 镜像后逆时针 90°、
 * 6 顺时针 90°、7 镜像后顺时针 90°、8 逆时针 90°）。imagerotate 的角度是逆时针。
 */
function imageApplyOrientation(GdImage $image, int $orientation): GdImage
{
    if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
    if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
    $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
    if ($angle !== 0) {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated instanceof GdImage) return $rotated;
    }
    return $image;
}

/** 读图并按 EXIF 方向转正（JPEG；其它格式原样）。缩略图、WebP、缩小超大图、图片编辑都走这里。 */
function imageLoadUpright(string $path, string $ext): GdImage|false
{
    $image = match (strtolower($ext)) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($path),
        'png'         => @imagecreatefrompng($path),
        'gif'         => @imagecreatefromgif($path),
        'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default       => false,
    };
    if (!$image instanceof GdImage) return false;
    $orientation = imageExifOrientation($path, $ext);
    return $orientation > 1 ? imageApplyOrientation($image, $orientation) : $image;
}

/** 转正后的宽高（方向 5–8 宽高互换）；读不到为 [0, 0]。 @return array{0:int,1:int} */
function imageUprightSize(string $path, string $ext): array
{
    $size = @getimagesize($path);
    if (!is_array($size)) return [0, 0];
    [$w, $h] = [(int) $size[0], (int) $size[1]];
    return imageExifOrientation($path, $ext) >= 5 ? [$h, $w] : [$w, $h];
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

    $srcImage = imageLoadUpright($filepath, $ext);

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
    // 覆盖原文件会丢掉 EXIF：先按方向转正，否则手机竖拍的大图存下来就横了
    $src = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? imageLoadUpright($filepath, $ext) : false;
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
 * 返回站内图片的实际尺寸；远程、缺失或越界路径均不触碰文件系统。
 *
 * @return array{0:int,1:int}
 */
function _localImageDimensions(string $url): array
{
    /** @var array<string,array{0:int,1:int}> $memo */
    static $memo = [];
    if (array_key_exists($url, $memo)) {
        return $memo[$url];
    }

    if ($url === '' || _isExternalUrl($url)) {
        return $memo[$url] = [0, 0];
    }

    $urlPath = parse_url($url, PHP_URL_PATH);
    if (!is_string($urlPath) || $urlPath === '' || str_contains($urlPath, "\0")) {
        return $memo[$url] = [0, 0];
    }

    $root = realpath(ROOT_PATH);
    if ($root === false) {
        return $memo[$url] = [0, 0];
    }

    $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($urlPath)), DIRECTORY_SEPARATOR);
    $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $insideRoot = $path !== false && (DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($path, $rootPrefix, strlen($rootPrefix)) === 0
        : strncmp($path, $rootPrefix, strlen($rootPrefix)) === 0);
    if (!$insideRoot) {
        return $memo[$url] = [0, 0];
    }

    $dimensions = @getimagesize($path);
    if ($dimensions === false) {
        return $memo[$url] = [0, 0];
    }

    return $memo[$url] = [
        max(0, (int) ($dimensions[0] ?? 0)),
        max(0, (int) ($dimensions[1] ?? 0)),
    ];
}

/**
 * 组合同一宽高比的本地缩略图与原图候选。
 *
 * @return array{src:string,srcset:string,webp_src:string,webp_srcset:string,width:int,height:int}
 */
function responsiveImageData(?string $url, string $preferredSize = 'medium'): array
{
    $original = (string) ($url ?? '');
    $memoKey = $original . "\0" . $preferredSize;
    /** @var array<string,array{src:string,srcset:string,webp_src:string,webp_srcset:string,width:int,height:int}> $memo */
    static $memo = [];
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    $source = thumbnail($original, $preferredSize);
    [$sourceWidth, $sourceHeight] = _localImageDimensions($source);
    [$originalWidth, $originalHeight] = _localImageDimensions($original);
    $candidates = [];

    if ($sourceWidth > 0 && $sourceHeight > 0) {
        $candidates[$sourceWidth] = $source;
    }
    if ($originalWidth > 0 && $originalHeight > 0) {
        $sameRatio = $sourceWidth < 1 || $sourceHeight < 1
            || abs(($sourceWidth / $sourceHeight) - ($originalWidth / $originalHeight)) < 0.01;
        if ($sameRatio) {
            $candidates[$originalWidth] = $original;
        }
    }

    ksort($candidates, SORT_NUMERIC);
    $webpCandidates = [];
    foreach ($candidates as $width => $candidate) {
        $webpCandidate = webpUrl($candidate);
        if ($webpCandidate === $candidate) {
            $webpCandidates = [];
            break;
        }
        $webpCandidates[$width] = $webpCandidate;
    }
    $srcsetParts = [];
    if (count($candidates) > 1) {
        foreach ($candidates as $width => $candidate) {
            $srcsetParts[] = $candidate . ' ' . (int) $width . 'w';
        }
    }

    $webpSource = '';
    $webpSrcsetParts = [];
    if ($candidates !== [] && count($webpCandidates) === count($candidates)) {
        $webpSource = $sourceWidth > 0 && isset($webpCandidates[$sourceWidth])
            ? $webpCandidates[$sourceWidth]
            : (string) reset($webpCandidates);
        if (count($webpCandidates) > 1) {
            foreach ($webpCandidates as $width => $candidate) {
                $webpSrcsetParts[] = $candidate . ' ' . (int) $width . 'w';
            }
        }
    }

    return $memo[$memoKey] = [
        'src' => $source,
        'srcset' => implode(', ', $srcsetParts),
        'webp_src' => $webpSource,
        'webp_srcset' => implode(', ', $webpSrcsetParts),
        'width' => $sourceWidth,
        'height' => $sourceHeight,
    ];
}

function responsiveImageAttributes(
    ?string $url,
    string $preferredSize = 'medium',
    string $sizes = '100vw'
): string {
    $image = responsiveImageData($url, $preferredSize);
    $escape = static fn(string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $attributes = ['src="' . $escape($image['src']) . '"'];

    if ($image['srcset'] !== '') {
        $attributes[] = 'srcset="' . $escape($image['srcset']) . '"';
        $attributes[] = 'sizes="' . $escape($sizes) . '"';
    }
    if ($image['width'] > 0 && $image['height'] > 0) {
        $attributes[] = 'width="' . $image['width'] . '"';
        $attributes[] = 'height="' . $image['height'] . '"';
    }

    return implode(' ', $attributes);
}

/**
 * 将图片转换为 WebP 格式
 */
function convertToWebp(string $srcPath, string $dstPath, string $srcExt, int $quality = 80): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }

    $srcExt = strtolower($srcExt);
    $srcImage = in_array($srcExt, ['jpg', 'jpeg', 'png'], true) ? imageLoadUpright($srcPath, $srcExt) : false;

    if (!$srcImage) return false;

    if ($srcExt === 'png') {
        imagepalettetotruecolor($srcImage);
        imagealphablending($srcImage, true);
        imagesavealpha($srcImage, true);
    }

    $temporaryPath = $dstPath . '.tmp-' . bin2hex(random_bytes(4));
    $result = imagewebp($srcImage, $temporaryPath, max(50, min(95, $quality)));
    unset($srcImage);
    if (!$result) {
        @unlink($temporaryPath);
        return false;
    }

    if (!@rename($temporaryPath, $dstPath)) {
        @unlink($dstPath);
        if (!@rename($temporaryPath, $dstPath)) {
            @unlink($temporaryPath);
            return false;
        }
    }

    return true;
}

/**
 * 列出原图及现有缩略图对应的 WebP 目标。
 *
 * @return array<string, array{source:string,target:string,current:bool}>
 */
function webpDerivativePlan(string $filepath, string $ext, bool $force = false): array
{
    $ext = strtolower(ltrim($ext, '.'));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true) || !is_file($filepath)) {
        return [];
    }

    $sources = ['original' => $filepath];
    foreach (array_keys(THUMBNAIL_SIZES) as $sizeName) {
        $thumbnailPath = _thumbnailPath($filepath, (string) $sizeName);
        if (is_file($thumbnailPath)) {
            $sources[(string) $sizeName] = $thumbnailPath;
        }
    }

    $plan = [];
    foreach ($sources as $name => $sourcePath) {
        $targetPath = preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $sourcePath);
        if (!is_string($targetPath) || $targetPath === $sourcePath) {
            continue;
        }
        $sourceMtime = @filemtime($sourcePath) ?: 0;
        $targetMtime = @filemtime($targetPath) ?: 0;
        $plan[$name] = [
            'source' => $sourcePath,
            'target' => $targetPath,
            'current' => !$force && is_file($targetPath) && $targetMtime >= $sourceMtime,
        ];
    }

    return $plan;
}

/**
 * 为原图及现有缩略图生成同名 WebP，供上传与历史媒体回填共用。
 *
 * @return array{generated:int,current:int,failed:int,targets:array<string,string>}
 */
function generateWebpDerivatives(
    string $filepath,
    string $ext,
    int $quality = 80,
    bool $force = false
): array {
    $result = ['generated' => 0, 'current' => 0, 'failed' => 0, 'targets' => []];
    $plan = webpDerivativePlan($filepath, $ext, $force);
    if ($plan === []) {
        return $result;
    }
    if (!function_exists('imagewebp')) {
        $result['failed'] = count($plan);
        return $result;
    }

    foreach ($plan as $name => $item) {
        if ($item['current']) {
            $result['current']++;
            $result['targets'][$name] = $item['target'];
            continue;
        }

        $sourceExt = strtolower(pathinfo($item['source'], PATHINFO_EXTENSION));
        if (convertToWebp($item['source'], $item['target'], $sourceExt, $quality)) {
            $result['generated']++;
            $result['targets'][$name] = $item['target'];
        } else {
            $result['failed']++;
        }
    }

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
