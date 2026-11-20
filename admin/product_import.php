<?php
/**
 * YikaiCMS - 产品导入 / 导出（2.3.0 起并入主程序；2.2.x 及以前是插件 product-import）
 *
 * 导入包（表格 + 图片）预览校验、错图检查、分批写入、整批撤销；CSV / Excel 字段映射；本站导出与比较。
 * 页面与接口在 admin/includes/product_import/，业务类在 includes/product-import/。
 * 任务与导入包仍放在 storage/product-import/（与插件时期同一位置，升级后旧任务照常可看、可撤销）。
 *
 * ?handler=upload / import / sample / pkg_* / job_* / jobs 是接口，其余是页面。写接口各自校验 CSRF。
 * PHP 8.2+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
// 权限跟产品走（插件时期只有超级管理员能用）
requirePermission('edit_product');

$currentMenu = 'product_import';
$pageTitle = __('pimp_page_title');

require ROOT_PATH . '/admin/includes/product_import/page.php';
