<?php
/**
 * Yikai CMS - 图形验证码
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

$width = 120;
$height = 40;
$length = 4;

// 生成随机字符（排除易混淆字符）
$chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < $length; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}

$_SESSION['member_captcha'] = strtolower($code);
$_SESSION['member_captcha_time'] = time();

// 创建图片
$image = imagecreatetruecolor($width, $height);

// 背景色
$bgColor = imagecolorallocate($image, 245, 245, 245);
imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

// 干扰线
for ($i = 0; $i < 4; $i++) {
    $lineColor = imagecolorallocate($image, random_int(150, 220), random_int(150, 220), random_int(150, 220));
    imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
}

// 干扰点
for ($i = 0; $i < 30; $i++) {
    $dotColor = imagecolorallocate($image, random_int(150, 220), random_int(150, 220), random_int(150, 220));
    imagesetpixel($image, random_int(0, $width), random_int(0, $height), $dotColor);
}

// 绘制文字
$fontSize = 5; // 内置字体大小 1-5
$charWidth = (int)($width / ($length + 1));
for ($i = 0; $i < $length; $i++) {
    $textColor = imagecolorallocate($image, random_int(20, 100), random_int(20, 100), random_int(20, 100));
    $x = $charWidth * $i + random_int(8, 15);
    $y = random_int(8, 15);
    imagestring($image, $fontSize, $x, $y, $code[$i], $textColor);
}

// 输出
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
imagepng($image);
imagedestroy($image);
