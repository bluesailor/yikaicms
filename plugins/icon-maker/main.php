<?php
/**
 * 图标工坊插件
 *
 * 前台钩子：应用过「全套图标包」后（iconmaker_applied=1），在 <head> 补充
 * apple-touch-icon / android-chrome / manifest 链接（favicon.ico 主题默认已输出）。
 *
 * 生成工具本体在 admin.php（/admin/plugin_page.php?plugin=icon-maker）。
 * 专业版能力凭 license_has_module('icon-maker') 解锁。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('ik_head', function () {
    if ((string) config('iconmaker_applied', '') !== '1') {
        return;
    }
    // 版本参数取应用时间戳，覆盖旧图标的浏览器缓存
    $v = preg_replace('/\D/', '', (string) config('iconmaker_applied_at', ''));
    $q = $v !== '' ? '?v=' . $v : '';
    echo "\n" . '<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png' . $q . '">'
       . "\n" . '<link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png' . $q . '">'
       . "\n" . '<link rel="manifest" href="/site.webmanifest' . $q . '">' . "\n";
});
