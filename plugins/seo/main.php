<?php
/**
 * SEO 工坊 - 前台引导
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

// 预留：后续在此挂前台 SEO 钩子（如 meta 增强、面包屑 Schema 补充等）。
