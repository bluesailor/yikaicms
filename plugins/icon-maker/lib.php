<?php
/**
 * 图标工坊 - 纯 GD 图像函数（不依赖 CMS 运行时，便于独立测试）
 */

declare(strict_types=1);

/** 缩放到 size×size（透明底、居中收纳，保留 alpha）。 */
function im_scaled(GdImage $src, int $size): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    // contain：短边贴合、居中
    $scale = min($size / $w, $size / $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $w, $h);
    imagealphablending($dst, false);
    return $dst;
}

/** PNG 字节串。 */
function im_png(GdImage $img): string
{
    ob_start();
    imagepng($img);
    return (string) ob_get_clean();
}

/**
 * 打包 ICO（经典 BMP 条目：32bpp BGRA + AND 掩码，兼容性最好）。
 * $sizes 建议 [16, 32, 48]。
 */
function im_ico(GdImage $master, array $sizes = [16, 32, 48]): string
{
    $entries = [];
    foreach ($sizes as $size) {
        $img = im_scaled($master, $size);
        $w = $h = $size;
        // 像素：自底向上，BGRA；GD alpha 0(不透明)-127(全透明) → 0-255
        $pix = '';
        for ($y = $h - 1; $y >= 0; $y--) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $pix .= chr($c & 0xFF) . chr(($c >> 8) & 0xFF) . chr(($c >> 16) & 0xFF) . chr(intdiv((127 - $a) * 255, 127));
            }
        }
        // AND 掩码：全 0（透明性由 alpha 通道决定），行按 32bit 对齐
        $maskRow = intdiv(intdiv($w + 7, 8) + 3, 4) * 4;
        $mask = str_repeat("\x00", $maskRow * $h);
        // BITMAPINFOHEADER：高度为双倍（XOR+AND）
        $bmp = pack('VVVvvVVVVVV', 40, $w, $h * 2, 1, 32, 0, strlen($pix) + strlen($mask), 0, 0, 0, 0) . $pix . $mask;
        $entries[] = ['w' => $w, 'h' => $h, 'data' => $bmp];
        imagedestroy($img);
    }
    $count = count($entries);
    $ico = pack('vvv', 0, 1, $count);
    $offset = 6 + 16 * $count;
    foreach ($entries as $e) {
        $ico .= chr($e['w'] < 256 ? $e['w'] : 0) . chr($e['h'] < 256 ? $e['h'] : 0) . "\x00\x00"
            . pack('vvVV', 1, 32, strlen($e['data']), $offset);
        $offset += strlen($e['data']);
    }
    foreach ($entries as $e) {
        $ico .= $e['data'];
    }
    return $ico;
}
