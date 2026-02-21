<?php
/**
 * Yikai CMS - 初始化文件
 *
 * 所有页面都需要引入此文件
 * PHP 8.0+
 */

declare(strict_types=1);

// 定义根目录
define('ROOT_PATH', dirname(__DIR__));

// 检查是否已安装
if (!file_exists(ROOT_PATH . '/installed.lock')) {
    header('Location: /install/');
    exit;
}

// 加载配置文件
require_once ROOT_PATH . '/config/config.php';

// 加载公共函数
require_once ROOT_PATH . '/includes/functions.php';

// 加载 Model 层
require_once ROOT_PATH . '/includes/models/autoload.php';

// 初始化语言
initLang();

// 加载前台会员认证
require_once ROOT_PATH . '/includes/member_auth.php';

// 加载钩子系统与插件
require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/plugin.php';
