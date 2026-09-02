<?php
/**
 * Minimal Theme - Header
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 获取站点配置
$siteName = configRawLang('site_name', 'Yikai CMS');
$siteLogo = SiteAsset::availableUrl((string) configRawLang('site_logo', ''));
$siteKeywords = config('site_keywords', '');
$siteDescription = config('site_description', '');

// 页头设置
$headerNavLayout = config('header_nav_layout', 'right');
$headerSticky = config('header_sticky', '1');
$headerBgColor = config('header_bg_color', '#ffffff');
$headerTextColor = config('header_text_color', '#4b5563');

// 顶部通栏设置
$topbarEnabled = config('topbar_enabled', '0') === '1';
$topbarBgColor = config('topbar_bg_color', '#f3f4f6');
$topbarLeft = config('topbar_left', '');

// 会员入口设置
$showMemberEntry = config('show_member_entry', '0') === '1';

// 页面标题
// 首页支持独立 SEO 标题
$seoTitle = config('seo_title', '');
if (empty($pageTitle) && !empty($seoTitle)) {
    $fullTitle = $seoTitle;
} else {
    $fullTitle = !empty($pageTitle) ? $pageTitle . ' - ' . $siteName : $siteName;
}

// SEO 变量（各页面可在 require header.php 前设置）
$siteUrl = siteBaseUrl();
$canonicalUrl = $canonicalUrl ?? ($siteUrl . ($_SERVER['REQUEST_URI'] ?? '/'));
$ogType = $ogType ?? 'website';
$ogImage = SiteAsset::availableUrl((string) ($ogImage ?? config('seo_og_image', '') ?: $siteLogo));
if ($ogImage && !str_starts_with($ogImage, 'http')) {
    $ogImage = $siteUrl . $ogImage;
}
$ogDescription = $pageDescription ?? $siteDescription;

// 获取导航栏目（带子栏目）
$navChannels = getDefaultNavigation(isset($navChannels) && is_array($navChannels) ? $navChannels : null);

// 当前栏目
$currentChannelId = $currentChannelId ?? 0;
$currentSlug = $currentSlug ?? '';

// 判断栏目是否激活（当前栏目或其父栏目，或匹配slug）
function isChannelActive(array $channel, int $currentId, string $currentSlug = ''): bool {
    // 检查ID匹配
    if ($currentId > 0 && (int)$channel['id'] === $currentId) {
        return true;
    }
    // 检查slug匹配
    if ($currentSlug !== '' && !empty($channel['slug']) && $channel['slug'] === $currentSlug) {
        return true;
    }
    // 检查子栏目
    if (!empty($channel['children'])) {
        foreach ($channel['children'] as $child) {
            if ($currentId > 0 && (int)$child['id'] === $currentId) {
                return true;
            }
            if ($currentSlug !== '' && !empty($child['slug']) && $child['slug'] === $currentSlug) {
                return true;
            }
        }
    }
    return false;
}

// 获取栏目链接（SEO友好URL）—— 委托给通用 channelUrl()，保留动态注入分支
function getChannelUrl(array $channel): string {
    if (!empty($channel['_url'])) return $channel['_url'];
    return channelUrl($channel);
}


?>
<!DOCTYPE html>
<html lang="<?php echo getLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="keywords" content="<?php echo e($pageKeywords ?? $siteKeywords); ?>">
    <meta name="description" content="<?php echo e($pageDescription ?? $siteDescription); ?>">
    <?php if ($baiduVerify = config('seo_baidu_verify')): ?>
    <meta name="baidu-site-verification" content="<?php echo e($baiduVerify); ?>">
    <?php endif; ?>
    <?php if ($googleVerify = config('seo_google_verify')): ?>
    <meta name="google-site-verification" content="<?php echo e($googleVerify); ?>">
    <?php endif; ?>
    <?php if ($bingVerify = config('seo_bing_verify')): ?>
    <meta name="msvalidate.01" content="<?php echo e($bingVerify); ?>">
    <?php endif; ?>
    <title><?php echo e($fullTitle); ?></title>
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <?php echo renderHreflangs(); ?>
    <link rel="icon" href="<?php echo e(config('site_favicon', '/favicon.ico')); ?>">
    <!-- OpenGraph -->
    <meta property="og:title" content="<?php echo e($fullTitle); ?>">
    <meta property="og:description" content="<?php echo e($ogDescription); ?>">
    <meta property="og:type" content="<?php echo e($ogType); ?>">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta property="og:site_name" content="<?php echo e($siteName); ?>">
    <?php if ($ogImage): ?>
    <meta property="og:image" content="<?php echo e($ogImage); ?>">
    <?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($fullTitle); ?>">
    <meta name="twitter:description" content="<?php echo e($ogDescription); ?>">
    <?php if ($ogImage): ?>
    <meta name="twitter:image" content="<?php echo e($ogImage); ?>">
    <?php endif; ?>
    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    <?php echo json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteName,
        'url' => $siteUrl,
        'logo' => $ogImage ?: null,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    </script>
    <?php if (!empty($jsonLd)): ?>
    <script type="application/ld+json">
    <?php echo json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    </script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo assetVer('/assets/css/tailwind.css'); ?>">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?php echo assetVer('/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo theme_asset('css/style.css'); ?>">
    <style>:root { --color-primary: <?php echo config('primary_color', '#3B82F6'); ?>; --color-secondary: <?php echo config('secondary_color', '#1D4ED8'); ?>; }</style>
    <?php if (!empty($extraCss)): ?>
    <?php echo $extraCss; ?>
    <?php endif; ?>
    <?php do_action('ik_head'); ?>
    <?php echo config('custom_head_code', ''); ?>
</head>
<body class="minimal-theme bg-white min-h-screen flex flex-col text-gray-800">

    <!-- Header -->
    <header id="siteHeader" class="<?php echo $headerSticky === '1' ? 'sticky top-0' : ''; ?> z-50 bg-white border-b border-gray-200">
        <div class="container mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Logo -->
                <a href="/" class="flex items-center gap-3">
                    <?php if ($siteLogo): ?>
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="h-8">
                    <?php else: ?>
                    <span class="text-lg font-light tracking-wide text-gray-900"><?php echo e($siteName); ?></span>
                    <?php endif; ?>
                </a>

                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center gap-8">
                    <?php foreach ($navChannels as $navItem): ?>
                    <?php
                    $hasChildren = !empty($navItem['children']);
                    $isActive = !empty($navItem['_is_home'])
                        ? !empty($isHomePage)
                        : isChannelActive($navItem, $currentChannelId, $currentSlug);
                    $navUrl = getChannelUrl($navItem);
                    $linkTarget = $navItem['type'] === 'link' ? ' target="' . e($navItem['link_target'] ?: '_self') . '"' : '';
                    $navCls = 'text-sm tracking-wide transition ' . ($isActive ? 'text-gray-900 font-medium' : 'text-gray-500 hover:text-gray-900');
                    ?>
                    <?php if ($hasChildren): ?>
                    <div class="nav-dropdown">
                        <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?> class="<?php echo $navCls; ?> inline-flex items-center gap-1">
                            <?php echo e($navItem['name']); ?>
                            <svg class="w-3 h-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </a>
                        <div class="nav-dropdown-menu">
                            <?php echo renderNavDropdownItems($navItem['children']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?> class="<?php echo $navCls; ?>"><?php echo e($navItem['name']); ?></a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($showMemberEntry): ?>
                    <span class="w-px h-4 bg-gray-200"></span>
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = $memberInfo ?? getMemberInfo(); ?>
                    <a href="/member/profile.php" class="text-sm text-gray-500 hover:text-gray-900 transition"><?php echo e($memberInfo['nickname']); ?></a>
                    <?php else: ?>
                    <a href="/member/login.php" class="text-sm text-gray-500 hover:text-gray-900 transition"><?php echo e(__('login_button')); ?></a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (config('show_lang_switcher', '0') === '1' && count(enabledLanguages()) > 1): ?>
                    <span class="w-px h-4 bg-gray-200"></span>
                    <div class="relative" id="langSwitcher">
                        <button type="button" onclick="document.getElementById('langDropdown').classList.toggle('hidden')" class="text-sm text-gray-500 hover:text-gray-900 transition inline-flex items-center gap-1">
                            <?php echo e(enabledLanguages()[siteLang()] ?? siteLang()); ?>
                            <svg class="w-3 h-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div id="langDropdown" class="hidden absolute right-0 mt-2 bg-white border border-gray-100 shadow-sm py-1 min-w-[100px] z-50">
                            <?php foreach (enabledLanguages() as $lk => $lv): ?>
                            <a href="javascript:switchLang('<?php echo $lk; ?>')" class="block px-4 py-2 text-sm hover:bg-gray-50 <?php echo siteLang() === $lk ? 'text-gray-900 font-medium' : 'text-gray-500'; ?>"><?php echo e($lv); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <script>
                    document.addEventListener('click', function (e) { var s = document.getElementById('langSwitcher'); if (s && !s.contains(e.target)) document.getElementById('langDropdown').classList.add('hidden'); });
                    function switchLang(lang) {
                        var defaultLang = <?php echo json_encode((string) config('site_lang', 'zh-CN')); ?>;
                        document.cookie = 'site_lang=' + lang + ';path=/;max-age=' + (365 * 86400);
                        var path = location.pathname.replace(/^\/(ja|en|zh-CN)\//, '/');
                        if (lang !== defaultLang) { path = '/' + lang + path; }
                        location.href = path + location.search;
                    }
                    </script>
                    <?php endif; ?>
                </nav>

                <!-- Mobile Hamburger -->
                <button id="mobileMenuBtn" class="md:hidden p-2 text-gray-600" aria-label="<?php echo e(__('menu_label')); ?>">
                    <div class="hamburger" id="hamburgerIcon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <nav id="mobileMenu" class="md:hidden hidden border-t border-gray-100 bg-white">
            <div class="container mx-auto px-6 py-6 space-y-4">
                <?php foreach ($navChannels as $navItem): ?>
                <a href="<?php echo getChannelUrl($navItem); ?>"
                   <?php echo $navItem['type'] === 'link' ? 'target="' . e($navItem['link_target'] ?: '_self') . '"' : ''; ?>
                   class="block text-sm tracking-wide text-gray-600 hover:text-gray-900">
                    <?php echo e($navItem['name']); ?>
                </a>
                <?php if (!empty($navItem['children'])): ?>
                <?php echo renderNavMobileItems($navItem['children'], 0, '', 'block text-sm tracking-wide text-gray-400 hover:text-gray-900'); ?>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($showMemberEntry): ?>
                <div class="border-t border-gray-100 pt-4">
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = $memberInfo ?? getMemberInfo(); ?>
                    <a href="/member/profile.php" class="block text-sm text-gray-600 hover:text-gray-900"><?php echo e($memberInfo['nickname']); ?></a>
                    <a href="/member/logout.php" class="block text-sm text-gray-400 hover:text-gray-900 mt-2"><?php echo e(__('admin_logout')); ?></a>
                    <?php else: ?>
                    <a href="/member/login.php" class="block text-sm text-gray-600 hover:text-gray-900"><?php echo e(__('login_button')); ?></a>
                    <?php if (config('allow_member_register') === '1'): ?>
                    <a href="/member/register.php" class="block text-sm text-gray-600 hover:text-gray-900 mt-2"><?php echo e(__('member_register')); ?></a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <?php do_action('ik_header_after'); ?>

    <!-- Main Content -->
    <main class="flex-1">
