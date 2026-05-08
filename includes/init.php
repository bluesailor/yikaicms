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

// 前台语言检测
if (!defined('SITE_LANG')) {
    $defaultSiteLang = (string)config('site_lang', 'zh-CN');
    $detected = $defaultSiteLang;

    // 仅在开启语言切换器时才检测 URL/cookie 中的语言
    if ((string)config('show_lang_switcher', '0') === '1') {
        $supported = ['zh-CN', 'ja', 'en'];
        if (!empty($_GET['_lang']) && in_array($_GET['_lang'], $supported, true)) {
            $detected = $_GET['_lang'];
        } elseif (!empty($_COOKIE['site_lang']) && in_array($_COOKIE['site_lang'], $supported, true)) {
            $detected = $_COOKIE['site_lang'];
        }
    }

    define('SITE_LANG', $detected);
}

// 加载前台会员认证
require_once ROOT_PATH . '/includes/member_auth.php';

// 加载钩子系统与插件
require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/Compatibility.php';
Compatibility::bootstrap();
require_once ROOT_PATH . '/includes/HtmlCache.php';
require_once ROOT_PATH . '/includes/HtmlPipeline.php';
HtmlPipeline::bootstrap();
require_once ROOT_PATH . '/includes/Abilities.php';
require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
require_once ROOT_PATH . '/includes/blocks/timeline.php';
require_once ROOT_PATH . '/includes/plugin.php';

// 前台启动完成，供插件挂载初始化逻辑
do_action('init');
