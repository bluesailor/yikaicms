<?php
declare(strict_types=1);

/**
 * 图片编辑的操作历史（纯函数，可单测；预览与保存共用）。
 *
 * 操作按顺序作用在「上一步之后的图」上，坐标都是该步图像的实际像素（不是预览像素）：
 *   {"op":"crop","x":10,"y":20,"w":800,"h":600}
 *   {"op":"rotate","deg":90}        顺时针，90 / 180 / 270（-90 视为 270）
 *   {"op":"flip","axis":"h"}         h 左右翻转，v 上下翻转
 * normalize() 逐步校验、把裁剪框钳回图内、合并相邻的旋转（角度相加）与同轴翻转（两次抵消），
 * 返回规范化后的操作与最终尺寸。前端只出选区，服务端每次从原图按这份历史重放。
 */
final class ImageEditPlan
{
    public const MAX_OPS = 50;
    /** 裁剪比例预设（宽:高）；free 不锁定 */
    public const RATIOS = ['free' => null, '1:1' => [1, 1], '4:3' => [4, 3], '3:2' => [3, 2], '16:9' => [16, 9], '3:1' => [3, 1]];

    /**
     * @param list<mixed> $ops
     * @return array{ops:list<array<string,int|string>>,width:int,height:int}
     */
    public static function normalize(int $width, int $height, array $ops): array
    {
        if ($width < 1 || $height < 1) throw new InvalidArgumentException('image_edit_invalid');
        if (count($ops) > self::MAX_OPS) throw new InvalidArgumentException('image_edit_too_many');
        $out = [];
        foreach ($ops as $op) {
            if (!is_array($op)) throw new InvalidArgumentException('image_edit_invalid');
            [$w0, $h0] = self::finalSize($width, $height, $out);   // 这一步之前的图有多大
            switch ($op['op'] ?? '') {
                case 'crop':
                    foreach (['x', 'y', 'w', 'h'] as $k) {
                        if (!isset($op[$k]) || !is_numeric($op[$k])) throw new InvalidArgumentException('image_edit_invalid');
                    }
                    $x = max(0, min($w0 - 1, (int) round((float) $op['x'])));
                    $y = max(0, min($h0 - 1, (int) round((float) $op['y'])));
                    $w = max(1, min($w0 - $x, (int) round((float) $op['w'])));
                    $h = max(1, min($h0 - $y, (int) round((float) $op['h'])));
                    if ($x === 0 && $y === 0 && $w === $w0 && $h === $h0) break;   // 全图裁剪等于没裁
                    $out[] = ['op' => 'crop', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
                    break;
                case 'rotate':
                    $deg = (((int) ($op['deg'] ?? 0)) % 360 + 360) % 360;
                    if (!in_array($deg, [0, 90, 180, 270], true) || !is_numeric($op['deg'] ?? null)) throw new InvalidArgumentException('image_edit_invalid');
                    $last = count($out) - 1;
                    if ($last >= 0 && $out[$last]['op'] === 'rotate') {
                        $deg = ((int) $out[$last]['deg'] + $deg) % 360;
                        array_pop($out);
                    }
                    if ($deg !== 0) $out[] = ['op' => 'rotate', 'deg' => $deg];
                    break;
                case 'flip':
                    $axis = $op['axis'] ?? '';
                    if ($axis !== 'h' && $axis !== 'v') throw new InvalidArgumentException('image_edit_invalid');
                    $last = count($out) - 1;
                    if ($last >= 0 && $out[$last]['op'] === 'flip' && $out[$last]['axis'] === $axis) {
                        array_pop($out);   // 同轴翻两次 = 没翻
                    } else {
                        $out[] = ['op' => 'flip', 'axis' => $axis];
                    }
                    break;
                default:
                    throw new InvalidArgumentException('image_edit_invalid');
            }
        }
        [$fw, $fh] = self::finalSize($width, $height, $out);
        return ['ops' => $out, 'width' => $fw, 'height' => $fh];
    }

    /**
     * 按规范化后的操作从原图尺寸推算结果尺寸：裁剪定尺寸，90° / 270° 旋转宽高互换，翻转不变。
     * @param list<array<string,int|string>> $ops
     * @return array{0:int,1:int}
     */
    public static function finalSize(int $width, int $height, array $ops): array
    {
        foreach ($ops as $op) {
            if ($op['op'] === 'crop') {
                [$width, $height] = [(int) $op['w'], (int) $op['h']];
            } elseif ($op['op'] === 'rotate' && (int) $op['deg'] % 180 !== 0) {
                [$width, $height] = [$height, $width];
            }
        }
        return [$width, $height];
    }

    /** 等比缩到最长边不超过 $max（不放大）。 @return array{0:int,1:int} */
    public static function fit(int $width, int $height, int $max): array
    {
        $longest = max($width, $height);
        if ($longest <= $max) return [$width, $height];
        $scale = $max / $longest;
        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }
}
