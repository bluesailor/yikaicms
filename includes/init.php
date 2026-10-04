<?php
/**
 * Yikai CMS - 初始化文件
 *
 * 所有页面都需要引入此文件
 * PHP 8.0+
 */

declare(strict_types=1);

// 最早的门：PHP 7 主机先给看得懂的提示，别让后面的 PHP 8 函数白屏
require_once __DIR__ . '/php_guard.php';

// 定义根目录
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));

// 子目录部署：剥入口前缀、挂出口改写。必须最先执行——
// 早于任何读取 REQUEST_URI 的代码，也早于其它 ob_start（最外层缓冲最后处理，拿到的才是成品）
require_once __DIR__ . '/BasePath.php';
BasePath::bootstrap();

require_once __DIR__ . '/RewriteProbe.php';
RewriteProbe::respond();

// 检查是否已安装
if (!file_exists(ROOT_PATH . '/installed.lock')) {
    header('Location: /install/');
    exit;
}

// 加载配置文件
require_once ROOT_PATH . '/config/config.php';

// 加载公共函数
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/language_request.php';

// 加载 Model 层
require_once ROOT_PATH . '/includes/models/autoload.php';
if (PHP_SAPI !== 'cli' && isDynamicUrlMode()) {
    require_once __DIR__ . '/CompatibleLinks.php';
    ob_start(static fn(string $html): string => CompatibleLinks::output($html));
}

// Custom paths pin the entity language before SITE_LANG, independently of browser cookies.
$customProductHit = null;
if (PHP_SAPI !== 'cli') {
    $customProductHit = productRouteModel()->resolve(customRouteLookupPath((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)));
    if ($customProductHit !== null) $_GET['_lang'] = $_REQUEST['_lang'] = $customProductHit['lang'];
}

// 初始化语言
initLang();

// 前台语言检测
//   优先级：URL 前缀（_lang，由 .htaccess 从 /en/.. /ja/.. 剥离传入）
//          > cookie（用户上次明确切换的语言，仅在切换器开启时生效）
//          > 站点默认（site_lang）
//
// URL 前缀总是被认；这是显式信号，且 SEO 必须保证 /en/foo 始终展示英文。
// cookie 仅在 show_lang_switcher='1' 时生效（避免悄悄改变默认语言行为）。
//
// 语言域名模式（LanguageDomains）：访问语言域名 = 该语言，优先于 cookie；带语言前缀或
// 走错主机的 GET 请求先 301 到规范地址（/en/xx 在主域名 → en.example.com/xx）。
$disabledLanguagePrefix = false;
$domainLanguage = null;
// 后台「多语言设置」的前缀探针：回答这次请求被认成了哪种语言（与服务器类型无关，见 LanguageRouting）
if (PHP_SAPI !== 'cli' && isset($_GET[LanguageRouting::PROBE_PARAM])) {
    LanguageRouting::respondProbe();
}
if (!defined('SITE_LANG') && PHP_SAPI !== 'cli' && LanguageDomains::active()) {
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD'], true) && !str_starts_with($requestPath, '/api/')) {
        $domainRedirect = LanguageDomains::redirectTarget($requestPath, (string) parse_url($requestUri, PHP_URL_QUERY));
        if ($domainRedirect !== null && !headers_sent()) {
            header('Location: ' . $domainRedirect, true, 301);
            exit;
        }
    }
    $domainLanguage = LanguageDomains::currentLanguage();
    // 存量页面里写死的 /en/xxx 等链接：输出时改到对应语言域名，省一次跳转
    ob_start(static fn(string $html): string => LanguageDomains::output($html));
}
if (!defined('SITE_LANG')) {
    $defaultSiteLang = (string)config('site_lang', 'zh-CN');
    $detected = $defaultSiteLang;

    $enabledRaw = trim((string)config('enabled_languages', ''));
    $supported = $enabledRaw !== '' ? json_decode($enabledRaw, true) : null;
    if (!is_array($supported) || $supported === []) {
        // 启用列表未配置：fallback 到 lang/ 目录里实际存在的语言（扫文件，不写死）
        $supported = array_keys(availableLanguages());
    }

    $requestedUrlLanguage = trim((string) ($_GET['_lang'] ?? ''));
    $disabledLanguagePrefix = languagePrefixIsDisabled(
        $requestedUrlLanguage,
        availableLanguages(),
        array_values(array_map('strval', $supported))
    );

    if ($requestedUrlLanguage !== '' && in_array($requestedUrlLanguage, $supported, true)) {
        $detected = $requestedUrlLanguage;
    } elseif ($domainLanguage !== null) {
        $detected = $domainLanguage;
    } elseif ((string)config('show_lang_switcher', '0') === '1'
              && !empty($_COOKIE['site_lang'])
              && in_array($_COOKIE['site_lang'], $supported, true)
              // 有独立域名的语言只在自己的域名上显示，主域名不按 cookie 渲染它（否则同一内容两处可见）
              && LanguageDomains::hostFor((string) $_COOKIE['site_lang']) === null) {
        $detected = (string)$_COOKIE['site_lang'];
    }

    define('SITE_LANG', $detected);
}

// 繁体中文（zh-TW）：作为简体的「渲染视图」——底层复用 zh-CN 内容与 UI 文案，
// 出页面前用 OpenCC 词库整页简→繁(台湾用词)。开销仅在 zh-TW 请求，接口(/api/)不转。
if (SITE_LANG === 'zh-TW' && strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/') === false) {
    require_once ROOT_PATH . '/includes/i18n/S2T.php';
    ob_start(['S2T', 'convertOutput']);
    // 访客输入的是繁体，内容存的是简体：搜索词先转回简体再查（页面回显时会再转成繁体）
    if (isset($_GET['keyword']) && is_string($_GET['keyword'])) {
        $_GET['keyword'] = S2T::toSimplified($_GET['keyword']);
    }
}

// 加载前台会员认证
require_once ROOT_PATH . '/includes/member_auth.php';
refreshMemberIdentity(true);

// 加载钩子系统与插件
require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/font_presets.php';
require_once ROOT_PATH . '/includes/Compatibility.php';
Compatibility::bootstrap();
require_once ROOT_PATH . '/includes/HtmlCache.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/TagEngine.php';
require_once ROOT_PATH . '/includes/Cron.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
// Cache invalidation is registered by HtmlCache.php; do not duplicate its policy here.
if (function_exists('add_action')) {
    // 旧站地址兜底（2.0.4）：即将 404 时先看跳转的前缀规则与 WordPress 规律地址
    add_action('render_404', [LegacyUrls::class, 'onNotFound']);
    // 吸顶头部滚动透明效果（前台 footer 输出，各主题通用；未启用时自动无输出）
    add_action('ik_footer_scripts', 'renderHeaderScrollFade');
    // 代码块复制按钮（正文含 <pre><code> 时才实际生效）
    add_action('ik_footer_scripts', 'renderCodeCopy');
    // 字体设置（未配置时 renderFontStyles() 返回 ''，前台输出逐字节不变）
    add_action('ik_head', static function (): void { echo renderFontStyles(); });
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
require_once ROOT_PATH . '/includes/front_edit.php';  // 前台就地编辑覆盖层（管理员悬停编辑区块）

// 已安装语言包但未启用的显式 URL 前缀不能回落成默认语言正文，否则形成软 404
// 与重复内容。完整初始化后走主题 404，确保头尾、插件钩子和语言文案都可用。
if ($disabledLanguagePrefix) {
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    render404();
}

// 定时发布 / 定时上架：到点的文章与产品（status=3）自动上线（status=1），见 ScheduledPublish。
// 无需 cron，由访问触发；限流每 60 秒最多扫描一次。
try {
    $sweepAt = (int) settingModel()->get('sched_sweep_at', '0');
    if (time() - $sweepAt >= 60) {
        require_once ROOT_PATH . '/includes/ScheduledPublish.php';
        ScheduledPublish::sweep();
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

// 标记「本次响应由 PHP 实时渲染」。html/ 下的静态文件由 Web 服务器直出、不经这里，
// 因此没有这个头——静态生成页的自检据此判断「管理员绕过静态」是否真的配好了。
// 对访客无副作用，也不泄露任何信息。
if (!headers_sent()) {
    header('X-Yikai-Render: dynamic');
}

// 前台启动完成，供插件挂载初始化逻辑
do_action('init');

dispatchCustomProductRoute($customProductHit);
