<?php
/**
 * Yikai CMS - 首页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// WordPress/宝塔 catch-all 会保留原始 /en、/ja 路径并把请求交给 index.php。
// 必须在 init.php 定义 SITE_LANG 前注入 _lang；Dispatcher::run() 里的解析已经太晚。
require_once __DIR__ . '/includes/Dispatcher.php';
$__incomingPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (empty($_GET['_lang'])) {
    $__incomingLang = Dispatcher::languagePrefixFromPath($__incomingPath);
    if ($__incomingLang !== null) {
        $_GET['_lang'] = $__incomingLang;
        $_REQUEST['_lang'] = $__incomingLang;
    }
}

require_once __DIR__ . '/includes/init.php';

// WP 式单入口路由：主机只配了两行 catch-all（如面板「WordPress 伪静态」预设）时，
// 伪静态 URL 会落到这里，由 Dispatcher 分发到对应入口文件；配了完整规则的主机
// 不受影响（服务器层已映射，不经 index.php）。顺带把旧行为里
// 「任意未知路径渲染首页」的软 404 改为真 404。
$__reqPath = $__incomingPath;
if ($__reqPath !== '/' && $__reqPath !== '/index.php') {
    Dispatcher::run();   // 命中即接管并 exit；语言前缀首页（/ja/）设 lang 后返回
}
unset($__incomingLang, $__incomingPath, $__reqPath);

HtmlCache::start(300);

// 页面信息
$isHomePage = true;
$pageTitle = '';
$pageKeywords = configJsonLang('site_keywords');
$pageDescription = configJsonLang('site_description');

// 获取首页数据
$banners = getBanners('home', 5);
$navChannels = getNavChannels();

// SEO: canonical
$siteUrl = siteBaseUrl();
$canonicalUrl = $siteUrl . (langPrefix() ?: '') . '/';

// 获取首页展示的栏目（is_home=1）
$homeChannels = channelModel()->getHomeChannels();

// 获取产品分类（用于首页产品区块）
$productCategories = productCategoryModel()->getTopLevel(6);

// 各栏目区块的展示设置（每行个数 / 显示数量 / 排序）从 home_blocks_config 读取
$_hbc = json_decode(config('home_blocks_config', ''), true) ?: [];
$channelBlockCfg = [];
foreach ($_hbc as $_b) {
    if (isset($_b['type']) && str_starts_with($_b['type'], 'channel:')) {
        $channelBlockCfg[(int) substr($_b['type'], 8)] = $_b;
    }
}

// 为每个栏目获取内容，并构建 ID => channel 映射
$homeChannelsMap = [];
foreach ($homeChannels as &$hChannel) {
    $cfg    = $channelBlockCfg[(int) $hChannel['id']] ?? [];
    $limit  = (int) ($cfg['limit'] ?? 8);
    if ($limit < 1) { $limit = 8; }
    $sort   = ($cfg['sort'] ?? 'recommend') === 'latest' ? 'latest' : 'recommend';
    $hChannel['per_row'] = (int) ($cfg['per_row'] ?? 4);

    if ($hChannel['type'] === 'product') {
        if ($sort === 'recommend') {
            $hChannel['contents'] = getProducts(0, $limit, 0, ['is_recommend' => true]);
            if (empty($hChannel['contents'])) {
                $hChannel['contents'] = getProducts(0, $limit, 0);
            }
        } else {
            $hChannel['contents'] = getProducts(0, $limit, 0);
        }
        $hChannel['is_product'] = true;
        $hChannel['categories'] = $productCategories;
    } else {
        if ($sort === 'recommend') {
            $hChannel['contents'] = getContents((int) $hChannel['id'], $limit, 0, ['include_children' => true, 'is_recommend' => true]);
            if (empty($hChannel['contents'])) {
                $hChannel['contents'] = getContents((int) $hChannel['id'], $limit, 0, ['include_children' => true]);
            }
        } else {
            $hChannel['contents'] = getContents((int) $hChannel['id'], $limit, 0, ['include_children' => true]);
        }
        $hChannel['is_product'] = false;
    }
    $homeChannelsMap[(int) $hChannel['id']] = $hChannel;
}
unset($hChannel);

// 获取关于我们栏目（用于简介区块，多语言感知）
$aboutChannel = getChannelBySlug('about', true);

// 轮播图高度配置
$bannerHeightPC = (int)config('banner_height_pc', 650);
$bannerHeightMobile = (int)config('banner_height_mobile', 300);
$bannerFullscreen = config('banner_fullscreen', '0') === '1';
$homeBannerRuntime = null;
// 首页 banner 优先采用「home 分组」自身的高度 / 全屏设置（后台 轮播图→分组管理→编辑 可配），回退到全局
$homeBannerGroup = getBannerGroup('home');
if ($homeBannerGroup) {
    $homeBannerRuntime = HomeBloxBlockSchema::bannerGroupRuntimeConfig($homeBannerGroup);
    if (!empty($homeBannerGroup['height_pc']))     { $bannerHeightPC = (int)$homeBannerGroup['height_pc']; }
    if (!empty($homeBannerGroup['height_mobile'])) { $bannerHeightMobile = (int)$homeBannerGroup['height_mobile']; }
    $bannerFullscreen = ($homeBannerRuntime['banner_height_mode'] ?? 'fixed') === 'screen';
}

// 主题色
$primaryColor = config('primary_color', '#2563EB');

// 客户评价数据
$testimonials = json_decode(configJsonLang('home_testimonials') ?: '[]', true) ?: [];

// 区块配置（顺序+开关）
$blocksConfig = json_decode(config('home_blocks_config', ''), true);
if (!$blocksConfig) {
    // 未配置时使用默认顺序（兼容未升级的情况）
    $blocksConfig = [
        ['type' => 'banner', 'enabled' => true],
        ['type' => 'about', 'enabled' => true],
        ['type' => 'stats', 'enabled' => true],
        ['type' => 'channels', 'enabled' => true],
        ['type' => 'testimonials', 'enabled' => true],
        ['type' => 'advantage', 'enabled' => true],
        ['type' => 'cta', 'enabled' => true],
    ];
}

// 迁移旧的 channels 整块为独立的 channel:{id} 区块
$channelIdsInConfig = [];
$migratedConfig = [];
foreach ($blocksConfig as $b) {
    if ($b['type'] === 'channels') {
        // 旧格式：展开为各个栏目独立区块
        foreach ($homeChannels as $hc) {
            $migratedConfig[] = ['type' => 'channel:' . $hc['id'], 'enabled' => $b['enabled'] ?? true];
            $channelIdsInConfig[] = (int)$hc['id'];
        }
    } else {
        $migratedConfig[] = $b;
        if (str_starts_with($b['type'], 'channel:')) {
            $channelIdsInConfig[] = (int)substr($b['type'], 8);
        }
    }
}
// 新增的首页栏目自动追加到末尾
foreach ($homeChannels as $hc) {
    if (!in_array((int)$hc['id'], $channelIdsInConfig)) {
        $migratedConfig[] = ['type' => 'channel:' . $hc['id'], 'enabled' => true];
    }
}
$blocksConfig = $migratedConfig;

// 合作伙伴接入区块系统（原先固定在页脚之上）：缺失则追加，启用状态沿用旧的 home_show_links
$__hasPartners = false;
foreach ($blocksConfig as $b) { if (($b['type'] ?? '') === 'partners') { $__hasPartners = true; break; } }
if (!$__hasPartners) {
    $blocksConfig[] = ['type' => 'partners', 'enabled' => (string) config('home_show_links', '0') === '1'];
}

// 区块模板映射
$blockTemplates = [
    'banner'       => theme_path('blocks/banner.php'),
    'about'        => theme_path('blocks/about.php'),
    'stats'        => theme_path('blocks/stats.php'),
    'testimonials' => theme_path('blocks/testimonials.php'),
    'advantage'    => theme_path('blocks/advantage.php'),
    'cta'          => theme_path('blocks/cta.php'),
    'partners'     => theme_path('blocks/partners.php'),
    'product_categories' => theme_path('blocks/product_categories.php'),
];

// 首页文档必须在 Header 输出前解析：全屏 Banner 可据此让页头叠放在首屏底图上。
$homeLayoutActive = HomeLayoutDocument::isActive() && HomeLayoutDocument::hasPublished();
$homeBloxActive = !$homeLayoutActive && HomeBloxDocument::isActive() && HomeBloxDocument::hasPublished();
$homeBloxDocument = BloxPublicationStatus::homeDraftPreview();
if (is_array($homeBloxDocument)) {
    $homeLayoutActive = false;
    $homeBloxActive = true;
} elseif ($homeLayoutActive) {
    $homeBloxDocument = HomeLayoutDocument::loadPublished();
} elseif ($homeBloxActive) {
    $homeBloxDocument = HomeBloxDocument::loadPublished();
}
$ykHomeHeaderOverlay = is_array($homeBloxDocument)
    ? HomeBloxRenderer::startsWithHeaderOverlayBanner(
        $homeBloxDocument['sections'],
        is_array($homeBannerGroup) ? $homeBannerGroup : []
    )
    : (is_array($homeBannerGroup)
        && HomeBloxRenderer::legacyStartsWithHeaderOverlayBanner($blocksConfig, $homeBannerGroup));
$ykHomeHeaderOverlayMobile = $ykHomeHeaderOverlay
    && (!is_array($homeBloxDocument)
        || HomeBloxRenderer::startsWithMobileVisibleBanner($homeBloxDocument['sections']));

// Swiper轮播图资源
// banner 高度：全屏模式用 100svh-头部(JS 量)，否则用配置的像素高度
$bannerHeightCss = $bannerFullscreen
    ? '.banner-swiper { height: ' . $bannerHeightMobile . 'px; }
@media (min-width: 768px) { .banner-swiper { height: calc(100vh - var(--hg-banner-offset, 70px)); height: calc(100svh - var(--hg-banner-offset, 70px)); } }'
    : '.banner-swiper { height: ' . $bannerHeightMobile . 'px; }
@media (min-width: 768px) { .banner-swiper { height: ' . $bannerHeightPC . 'px; } }';
BloxAssetCollector::addStyle('/assets/css/blox-banner.css');
$extraCss = '
<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">
' . BloxAssetCollector::renderStyles() . '
<style>
' . $bannerHeightCss . '
.banner-swiper .swiper-pagination-bullet-active { opacity: 1; background: ' . $primaryColor . '; width: 24px; border-radius: 6px; }
/* fade 效果下所有 slide 叠放：非激活 slide 的按钮(.pointer-events-auto)会盖在上面截获点击 → 强制只让激活 slide 可点 */
.banner-swiper .swiper-slide:not(.swiper-slide-active),
.banner-swiper .swiper-slide:not(.swiper-slide-active) * { pointer-events: none !important; }
.banner-swiper .swiper-slide-active .pointer-events-auto { pointer-events: auto !important; }
</style>';
if ($ykHomeHeaderOverlay) {
    $headerOverlayScript = $ykHomeHeaderOverlayMobile
        ? 'document.documentElement.classList.add("yk-home-header-overlay");'
        : '(function(){var q=window.matchMedia("(min-width: 768px)");function s(){document.documentElement.classList.toggle("yk-home-header-overlay",q.matches);}if(q.addEventListener){q.addEventListener("change",s);}else if(q.addListener){q.addListener(s);}s();})();';
    $extraCss .= '
<script>' . $headerOverlayScript . '</script>
<style>
html.yk-home-header-overlay body > [data-yk-topbar] {
    position: absolute !important;
    inset: 0 0 auto;
    z-index: 60;
    background: rgba(15, 23, 42, .42) !important;
    color: #fff !important;
}
html.yk-home-header-overlay body > [data-yk-topbar] * { color: inherit !important; }
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) {
    position: absolute !important;
    inset: 0 0 auto;
    z-index: 55;
    background: transparent !important;
    box-shadow: none !important;
    border-color: rgba(255, 255, 255, .18) !important;
}
html.yk-home-header-overlay body > [data-yk-topbar] + #siteHeader { top: 2rem; }
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) a,
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) button,
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) a > span { color: #fff !important; }
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) .hamburger span { background-color: #fff !important; }
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) .nav-dropdown-menu a,
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) .nav-submenu a,
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) #langDropdown a { color: #374151 !important; }
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) #mobileMenu {
    background: rgba(15, 23, 42, .96) !important;
    border-color: rgba(255, 255, 255, .14) !important;
}
html.yk-home-header-overlay #siteHeader:not(.yk-blox-header) #mobileMenu a { color: #fff !important; }
</style>';
}
if ($bannerFullscreen) {
    // 满屏 banner：量出头部（通栏+导航）总高度写入 --hg-banner-offset；svh 适配移动端浏览器地址栏
    $extraCss .= '
<script>(function(){function s(){var b=document.querySelector(".banner-swiper");if(!b)return;var t=b.getBoundingClientRect().top+(window.pageYOffset||document.documentElement.scrollTop||0);document.documentElement.style.setProperty("--hg-banner-offset",Math.round(t)+"px");}window.addEventListener("DOMContentLoaded",s);window.addEventListener("load",s);window.addEventListener("resize",s);s();})();</script>';
}

BloxAssetCollector::addScript('/assets/js/blox-video-policy.js');
BloxAssetCollector::addScript('/assets/js/blox-banner.js');

$extraJs = '
<script src="/assets/swiper/swiper-bundle.min.js"></script>
<script>
// 首页产品分类筛选
(function() {
    const nav = document.getElementById("productCategoryNav");
    const grid = document.getElementById("productGrid");
    if (!nav || !grid) return;

    const buttons = nav.querySelectorAll(".category-btn");
    const items = grid.querySelectorAll(".product-item");

    buttons.forEach(btn => {
        btn.addEventListener("click", function() {
            const cat = this.dataset.category;

            // 更新按钮状态
            buttons.forEach(b => {
                b.classList.remove("bg-primary", "text-white");
                b.classList.add("bg-gray-100", "text-gray-600");
            });
            this.classList.remove("bg-gray-100", "text-gray-600");
            this.classList.add("bg-primary", "text-white");

            // 筛选产品
            items.forEach(item => {
                const itemCat = item.dataset.category;
                if (cat === "all" || itemCat === cat) {
                    item.style.display = "";
                    item.style.opacity = "0";
                    setTimeout(() => { item.style.opacity = "1"; }, 50);
                } else {
                    item.style.display = "none";
                }
            });
        });
    });
})();
</script>';

// 引入头部
require_once theme_path('layouts/header.php');

// 首页统一从顶部管理条进入 Blox，不再给每个区块叠加悬停编辑框。
// 该变量仍传给主题区块，明确关闭仅供编辑器预览使用的字段标记。
$ykHomeEdit = false;
if (!isCleanFrontendPreview() && !empty($_SESSION['admin_id'])) {
    $GLOBALS['ik_edit_url'] = bloxAdvancedFeaturesEnabled()
        ? '/admin/blox_editor.php?home=1'
        : '/admin/setting_home.php';
}

// 动态渲染首页：已发布 Blox 时按文档树输出，未发布时保留旧首页链路。
$homeBloxRenderContext = HomeBloxRenderContext::fromHomePageData(
    $blocksConfig,
    $blockTemplates,
    $homeChannelsMap,
    $banners,
    $aboutChannel,
    $testimonials,
    $ykHomeEdit
);
$renderLegacyHomeBlock = [$homeBloxRenderContext, 'renderLegacyBlock'];

if (($homeLayoutActive || $homeBloxActive) && is_array($homeBloxDocument)) {
    echo HomeBloxRenderer::render($homeBloxDocument['sections'], $renderLegacyHomeBlock);
} else {
    foreach ($blocksConfig as $block) {
        if (empty($block['enabled'])) continue;
        $type = $block['type'] ?? '';

        // 独立栏目区块仍由旧主题模板负责实际数据查询与输出。
        if (str_starts_with($type, 'channel:')) {
            $channelId = (int) substr($type, 8);
            $currentChannel = $homeChannelsMap[$channelId] ?? null;
            if (!$currentChannel) {
                // 配置存的是别的语言行的 id：按翻译组映射后再查富化映射表
                $__sib = channelModel()->siblingForLang($channelId);
                $currentChannel = $__sib ? ($homeChannelsMap[(int) $__sib['id']] ?? null) : null;
            }
            if ($currentChannel) {
                require theme_path('blocks/channel.php');
            }
        } elseif (str_starts_with($type, 'custom:')) {
            // 自定义版块来自预设库：home_custom_<N> = {title, blocks}。
            $customData = json_decode((string) config('home_custom_' . substr($type, 7), ''), true);
            if (!empty($customData['blocks']) && function_exists('renderBlocksToHtml')) {
                echo renderBlocksToHtml(json_encode($customData['blocks'], JSON_UNESCAPED_UNICODE));
            }
        } elseif (isset($blockTemplates[$type]) && file_exists($blockTemplates[$type])) {
            if ($type === 'banner' && is_array($homeBannerRuntime)) {
                $block = array_merge($block, $homeBannerRuntime);
            }
            require $blockTemplates[$type];
        } else {
            // 插件版块前台渲染扩展点：内置类型不匹配时交给插件。
            echo (string) apply_filters('home_block_render', '', $type, $block);
        }

    }
}

// Business 主题的旧 CTA 位于 footer；Blox 启用后只允许文档中的 CTA 区块输出。
if ($homeLayoutActive || $homeBloxActive) {
    $GLOBALS['ik_hide_footer_cta'] = true;
}
// 合作伙伴 / 友情链接由上方区块系统按 home_blocks_config 的位置渲染。
require_once theme_path('layouts/footer.php');
HtmlCache::end();
?>
