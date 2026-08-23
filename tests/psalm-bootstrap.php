<?php
/**
 * Psalm 引导文件（psalm.xml 的 autoloader）。
 *
 * yikaicms 是「函数式」架构：post()/config()/db()/channelModel()/__() 等全局助手
 * 由 require 加载、而非 composer 自动加载类。Psalm 静态扫描不会把它们登记进符号表，
 * 于是把每一处调用都报成 UndefinedFunction，只能靠庞大的 psalm-baseline.xml 整体抑制
 * ——连真正缺失的函数（如曾经的 getAdminId）也一并被抑制掉了。
 *
 * 这里在 Psalm 启动时把这些「定义文件」加载一遍，让 Psalm 认识全部真实全局助手。
 * 之后 baseline 里的 UndefinedFunction 噪声可清掉，真·未定义函数会当场暴露。
 *
 * 只为「登记符号」，不连数据库、不产生副作用（这些文件顶层只定义函数/类）。
 */
declare(strict_types=1);

error_reporting(E_ERROR | E_PARSE);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
// 跳过 config 里的 session_start() 等 web 副作用（仅登记符号）。
if (!defined('IK_CLI')) {
    define('IK_CLI', 1);
}

// 先加载模板 config，拿到 DB_*/路径/CSRF 等全部常量（database.php 等在顶层可能引用）。
require_once ROOT_PATH . '/config/config.php.example';

// 函数式全局助手的定义源。
require_once ROOT_PATH . '/config/database.php';          // db(), Database 类
require_once ROOT_PATH . '/includes/security.php';        // zipUnsafeEntry()/sanitizeSvg()（functions.php 依赖）
require_once ROOT_PATH . '/includes/functions.php';       // post/config/e/__/success/error/...
require_once ROOT_PATH . '/includes/models/autoload.php'; // channelModel()/productModel()/...
require_once ROOT_PATH . '/includes/TagEngine.php';       // TagEngine 模板标签
require_once ROOT_PATH . '/admin/includes/auth.php';      // checkLogin/requirePermission/getAdminId/...
require_once ROOT_PATH . '/includes/CLI.php';             // includes/commands/* 的 CLI::register()
require_once ROOT_PATH . '/includes/builder/AbstractElement.php';
require_once ROOT_PATH . '/includes/builder/elements/ContentCatalogElement.php';
require_once ROOT_PATH . '/includes/builder/elements/ProductCatalogElement.php';

// 其余按需加载的运行时助手（前台/会员/时间线）。
require_once ROOT_PATH . '/includes/blocks/timeline.php';      // getTimelineIcon()
require_once ROOT_PATH . '/includes/customer_service.php';     // renderCustomerService()
require_once ROOT_PATH . '/includes/member_auth.php';         // getMemberInfo()
