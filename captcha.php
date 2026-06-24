<?php
/**
 * YikaiCMS - 表单图形验证码（GD 动态生成，答案存 session，与 form_submit.php 共享）。
 * 表单模板「启用验证码」开关打开时由 renderFormTemplate() 引用。
 */
require_once __DIR__ . '/includes/init.php';

// 清掉任何已有输出缓冲，确保返回纯 PNG
while (ob_get_level() > 0) { ob_end_clean(); }

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混字符
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['form_captcha']    = strtolower($code);
$_SESSION['form_captcha_ts'] = time();

$w = 120;
$h = 42;
$img = imagecreatetruecolor($w, $h);
imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 247, 248, 240));

for ($i = 0; $i < 5; $i++) {
    imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h),
        imagecolorallocate($img, random_int(150, 210), random_int(160, 210), random_int(110, 170)));
}
for ($i = 0; $i < 110; $i++) {
    imagesetpixel($img, random_int(0, $w), random_int(0, $h),
        imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(120, 190)));
}
for ($i = 0; $i < strlen($code); $i++) {
    $tc = imagecolorallocate($img, random_int(20, 90), random_int(70, 130), random_int(10, 60));
    imagestring($img, 5, 14 + $i * 26 + random_int(-2, 2), random_int(9, 17), $code[$i], $tc);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
imagepng($img);
imagedestroy($img);
