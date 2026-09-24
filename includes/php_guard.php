<?php
/**
 * 运行环境底线：PHP 低于 8.0 时给出看得懂的提示，而不是白屏或一行 Fatal。
 *
 * 入口（index.php、install/index.php、includes/init.php）在其它任何 require 之前引入本文件——
 * 后面的代码会调用 str_starts_with() 等 PHP 8 函数，老主机上会直接致命错误。
 *
 * 本文件必须保持 PHP 5.x / 7.x 可解析：不要在这里用 PHP 8 语法或函数。
 * 版本线与 RuntimeRequirements::PHP_MINIMUM 保持一致（那里是唯一来源，这里只是最早的门）。
 * 覆盖范围：PHP 7.4（老主机最常见的版本）。入口与 BasePath/init 用了箭头函数，
 * 7.0–7.3 会在解析阶段就报错、到不了这里——那几个版本已基本绝迹，不为它们改写入口。
 */

/** @psalm-suppress ParadoxicalCondition Psalm 按 PHP 8 分析，这里恰恰是给更老的 PHP 看的。 */
if (PHP_VERSION_ID < 80000) {
    if (!headers_sent()) {
        // 状态码走 header() 第三参：专门的设状态码函数要 PHP 5.4+，这里连 5.x 都要能跑
        header('Content-Type: text/html; charset=utf-8', true, 500);
    }
    $ykPhpVersion = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>需要 PHP 8.0 或更高版本</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:10vh auto;padding:0 20px;line-height:1.7;color:#1f2937">'
        . '<h1 style="font-size:22px">需要 PHP 8.0 或更高版本</h1>'
        . '<p>YikaiCMS 需要 PHP 8.0 或更高版本，当前服务器是 PHP ' . $ykPhpVersion . '。</p>'
        . '<p>请在主机控制面板（宝塔、小皮、易开面板等）把本站的 PHP 版本切换到 8.0 或更高（推荐 8.2），然后刷新本页。</p>'
        . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0">'
        . '<p style="color:#6b7280;font-size:14px">YikaiCMS requires PHP 8.0 or newer (8.2 recommended). This server runs PHP '
        . $ykPhpVersion . '. Switch the site to PHP 8.0+ in your hosting control panel, then reload this page.</p>'
        . '</body></html>';
    exit(1);
}
