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
//   优先级：URL 前缀（_lang，由 .htaccess 从 /en/.. /ja/.. 剥离传入）
//          > cookie（用户上次明确切换的语言，仅在切换器开启时生效）
//          > 站点默认（site_lang）
//
// URL 前缀总是被认；这是显式信号，且 SEO 必须保证 /en/foo 始终展示英文。
// cookie 仅在 show_lang_switcher='1' 时生效（避免悄悄改变默认语言行为）。
if (!defined('SITE_LANG')) {
    $defaultSiteLang = (string)config('site_lang', 'zh-CN');
    $detected = $defaultSiteLang;

    $enabledRaw = trim((string)config('enabled_languages', ''));
    $supported = $enabledRaw !== '' ? json_decode($enabledRaw, true) : null;
    if (!is_array($supported) || $supported === []) {
        // 启用列表未配置：fallback 到 lang/ 目录里实际存在的语言（扫文件，不写死）
        $supported = array_keys(availableLanguages());
    }

    if (!empty($_GET['_lang']) && in_array($_GET['_lang'], $supported, true)) {
        $detected = (string)$_GET['_lang'];
    } elseif ((string)config('show_lang_switcher', '0') === '1'
              && !empty($_COOKIE['site_lang'])
              && in_array($_COOKIE['site_lang'], $supported, true)) {
        $detected = (string)$_COOKIE['site_lang'];
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
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/TagEngine.php';
require_once ROOT_PATH . '/includes/Cron.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
// 内容/产品/栏目/设置等数据变更时，自动清除前台 HTML 缓存（避免改完后台不生效）
if (function_exists('add_action')) {
    add_action('data_changed', function () {
        if (class_exists('HtmlCache')) {
            HtmlCache::invalidate();
        }
    });
    // 吸顶头部滚动透明效果（前台 footer 输出，各主题通用；未启用时自动无输出）
    add_action('ik_footer_scripts', 'renderHeaderScrollFade');
}
require_once ROOT_PATH . '/includes/StaticHtml.php';
require_once ROOT_PATH . '/includes/HtmlPipeline.php';
HtmlPipeline::bootstrap();
require_once ROOT_PATH . '/includes/Abilities.php';
require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
require_once ROOT_PATH . '/includes/blocks/timeline.php';
require_once ROOT_PATH . '/includes/customer_service.php';
require_once ROOT_PATH . '/includes/plugin.php';
require_once ROOT_PATH . '/includes/License.php';
require_once ROOT_PATH . '/includes/admin_bar.php';   // 前台管理工具条（登录管理员可见）

// 定时发布：到点的定时内容（status=3）自动上线为已发布（status=1）。
// 无需 cron，由访问触发；限流每 60 秒最多扫描一次。
try {
    $sweepAt = (int) settingModel()->get('sched_sweep_at', '0');
    if (time() - $sweepAt >= 60) {
        contentModel()->promoteDue();
        settingModel()->set('sched_sweep_at', (string) time(), 'system');
    }
} catch (\Throwable $e) {
    // 安装未完成 / 表缺失时静默跳过
}

// 站点覆盖层逻辑入口：overrides/bootstrap.php（若存在）。
// 在插件加载之后、init 之前载入，站点可在此 add_action/add_filter 挂载/覆盖逻辑，
// 无需改核心/插件文件，升级不冲突。见 overrides/README.md。
$__ovBootstrap = ROOT_PATH . '/overrides/bootstrap.php';
if (is_file($__ovBootstrap)) {
    try {
        require_once $__ovBootstrap;
    } catch (\Throwable $e) {
        error_log('overrides/bootstrap.php error: ' . $e->getMessage());
    }
}

// 前台启动完成，供插件挂载初始化逻辑
do_action('init');
