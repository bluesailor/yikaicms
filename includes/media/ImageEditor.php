<?php
declare(strict_types=1);

require_once __DIR__ . '/ImageEditPlan.php';

/**
 * 按操作历史重放图片编辑（GD）。预览与保存走同一段 render()，所见即所得。
 * 只处理 jpg / png / gif / webp；透明通道保留（gif 动图只取第一帧——编辑器里会提示）。
 */
final class ImageEditor
{
    public const EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function supported(string $ext): bool
    {
        return in_array(strtolower($ext), self::EXTS, true) && function_exists('imagecreatetruecolor');
    }

    /**
     * @param list<array<string,int|string>> $ops 已规范化（ImageEditPlan::normalize）
     * @param int $maxSide 大于 0 时把结果等比缩到最长边不超过它（预览）
     */
    public static function render(string $path, string $ext, array $ops, int $maxSide = 0): GdImage
    {
        $ext = strtolower($ext);
        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$image instanceof GdImage) throw new RuntimeException('image_edit_load_failed');
        if (!imageistruecolor($image)) imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        foreach ($ops as $op) {
            $next = match ($op['op']) {
                'crop' => imagecrop($image, ['x' => (int) $op['x'], 'y' => (int) $op['y'], 'width' => (int) $op['w'], 'height' => (int) $op['h']]),
                // GD 是逆时针转；顺时针 90° = 逆时针 270°
                'rotate' => imagerotate($image, (float) (360 - (int) $op['deg']), (int) imagecolorallocatealpha($image, 0, 0, 0, 127)),
                'flip' => imageflip($image, $op['axis'] === 'h' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL) ? $image : false,
                default => false,
            };
            if (!$next instanceof GdImage) throw new RuntimeException('image_edit_failed');
            $image = $next;
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        if ($maxSide > 0) {
            [$w, $h] = ImageEditPlan::fit(imagesx($image), imagesy($image), $maxSide);
            if ($w !== imagesx($image)) {
                $scaled = imagecreatetruecolor($w, $h);
                if (!$scaled instanceof GdImage) throw new RuntimeException('image_edit_failed');
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));
                $image = $scaled;
            }
        }
        return $image;
    }

    /** 写成与原文件同格式的文件（质量与上传压缩同口径）。 */
    public static function save(GdImage $image, string $path, string $ext): bool
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => imagejpeg($image, $path, 88),
            'png' => imagepng($image, $path, 6),
            'gif' => imagegif($image, $path),
            'webp' => function_exists('imagewebp') && imagewebp($image, $path, 85),
            default => false,
        };
    }
}
