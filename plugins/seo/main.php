<?php
/**
 * SEO 助手 - 前台引导
 *
 * llms.txt 是站点根静态文件（/llms.txt），无需前台 <head> 注入，故本文件从简。
 * 功能本体在 admin.php（/admin/plugin_page.php?plugin=seo），纯函数在 lib.php。
 * 专业版能力凭 license_has_module('seo-pro') 解锁。
 *
 * PHP 8.0+
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 后台内容编辑页：注入「实时 SEO 分析」面板脚本（自门控，非编辑页不激活）。
add_action('ik_admin_footer_scripts', function () {
    $js = ROOT_PATH . '/plugins/seo/analysis.js';
    $v = @filemtime($js) ?: '1';
    $pro = function_exists('license_has_module') && license_has_module('seo-pro') ? 'true' : 'false';
    echo "\n" . '<script>window.__ykSeoPro=' . $pro . ';</script>';
    echo "\n" . '<script src="/plugins/seo/analysis.js?v=' . $v . '"></script>' . "\n";
});

// 重定向管理器（专业版）：前台匹配跳转 + 404 记录。函数内再判 Pro 与表存在，非 Pro 零开销。
require_once __DIR__ . '/redirects.php';
add_action('init', 'seo_redirect_apply');
add_action('render_404', 'seo_redirect_log404');

// 搜索引擎自动推送（专业版）：注册 cron 任务；任务体内部再判 Pro 与开关。
require_once __DIR__ . '/autopush.php';
add_action('cron_register', 'seo_autopush_register');
