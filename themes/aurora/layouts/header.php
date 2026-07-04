<?php
/**
 * Aurora Theme - Header
 *
 * 现代科技风 / 深色 / 渐变
 * PHP 8.0+
 */

declare(strict_types=1);

// 站点配置
$siteName = configRawLang('site_name', 'Yikai CMS');
$siteLogo = configRawLang('site_logo', '');
$siteKeywords = config('site_keywords', '');
$siteDescription = config('site_description', '');

// 页头设置
$headerSticky = config('header_sticky', '1');

// 顶部通栏
$topbarEnabled = config('topbar_enabled', '0') === '1';
$topbarLeft = config('topbar_left', '');

// 会员入口
$showMemberEntry = config('show_member_entry', '0') === '1';

// 页面标题
$seoTitle = config('seo_title', '');
if (empty($pageTitle) && !empty($seoTitle)) {
    $fullTitle = $seoTitle;
} else {
    $fullTitle = !empty($pageTitle) ? $pageTitle . ' - ' . $siteName : $siteName;
}

// SEO 变量
$siteUrl = rtrim(config('site_url', SITE_URL), '/');
$canonicalUrl = $canonicalUrl ?? ($siteUrl . ($_SERVER['REQUEST_URI'] ?? '/'));
$ogType = $ogType ?? 'website';
$ogImage = $ogImage ?? config('seo_og_image', '') ?: configRawLang('site_logo', '');
if ($ogImage && !str_starts_with($ogImage, 'http')) {
    $ogImage = $siteUrl . $ogImage;
}
$ogDescription = $pageDescription ?? $siteDescription;

// 导航数据
if (!isset($navChannels)) {
    $navChannels = getNavChannels();
}
$currentChannelId = $currentChannelId ?? 0;
$currentSlug = $currentSlug ?? '';

// 栏目激活判断
function isChannelActive(array $channel, int $currentId, string $currentSlug = ''): bool {
    if ($currentId > 0 && (int)$channel['id'] === $currentId) return true;
    if ($currentSlug !== '' && !empty($channel['slug']) && $channel['slug'] === $currentSlug) return true;
    if (!empty($channel['children'])) {
        foreach ($channel['children'] as $child) {
            if ($currentId > 0 && (int)$child['id'] === $currentId) return true;
            if ($currentSlug !== '' && !empty($child['slug']) && $child['slug'] === $currentSlug) return true;
        }
    }
    return false;
}

function getChannelUrl(array $channel): string {
    if (!empty($channel['_url'])) return $channel['_url'];
    return channelUrl($channel);
}
?>
<!DOCTYPE html>
<html lang="<?php echo getLang(); ?>" class="aurora-theme">
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
    <!-- JSON-LD -->
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
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <link rel="stylesheet" href="<?php echo theme_asset('css/style.css'); ?>">
    <style>:root { --color-primary: <?php echo config('primary_color', '#6366F1'); ?>; --color-secondary: <?php echo config('secondary_color', '#8B5CF6'); ?>; }</style>
    <?php if (!empty($extraCss)): ?>
    <?php echo $extraCss; ?>
    <?php endif; ?>
    <?php do_action('ik_head'); ?>
    <?php echo config('custom_head_code', ''); ?>
</head>
<body class="bg-slate-950 text-slate-300 min-h-screen flex flex-col antialiased">

    <!-- 装饰背景：渐变光斑 + 网格 -->
    <div class="fixed inset-0 pointer-events-none -z-10 overflow-hidden">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-[1200px] h-[800px] bg-gradient-radial from-indigo-500/20 via-purple-500/10 to-transparent blur-3xl"></div>
        <div class="absolute inset-0 aurora-grid opacity-[0.04]"></div>
    </div>

    <?php if ($topbarEnabled && $topbarLeft): ?>
    <!-- Topbar -->
    <div class="border-b border-slate-800/60 bg-slate-900/40 backdrop-blur-sm">
        <div class="container mx-auto px-6 lg:px-8 h-9 flex items-center text-xs text-slate-400">
            <span><?php echo e($topbarLeft); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="<?php echo $headerSticky === '1' ? 'sticky top-0' : ''; ?> z-50 bg-slate-950/70 backdrop-blur-xl border-b border-slate-800/60">
        <div class="container mx-auto px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="/" class="flex items-center gap-2.5 group">
                    <?php if ($siteLogo): ?>
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="h-8">
                    <?php else: ?>
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-sm font-bold shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 transition">
                        <?php echo e(mb_substr($siteName, 0, 1)); ?>
                    </span>
                    <span class="text-base font-semibold tracking-tight text-slate-100"><?php echo e($siteName); ?></span>
                    <?php endif; ?>
                </a>

                <!-- Desktop Nav -->
                <nav class="hidden md:flex items-center gap-1">
                    <?php if (configRawLang('nav_home_show', '1') !== '0'): ?>
                    <?php $homeActive = isset($isHomePage) && $isHomePage; ?>
                    <a href="/" class="px-3 py-2 rounded-lg text-sm font-medium transition <?php echo $homeActive ? 'text-white bg-white/5' : 'text-slate-400 hover:text-white hover:bg-white/5'; ?>">
                        <?php echo e(configLang('nav_home_text', 'nav_home')); ?>
                    </a>
                    <?php endif; ?>
                    <?php foreach ($navChannels as $navItem): ?>
                    <?php
                    $isActive = isChannelActive($navItem, $currentChannelId, $currentSlug);
                    $navUrl = getChannelUrl($navItem);
                    $linkTarget = $navItem['type'] === 'link' ? ' target="' . e($navItem['link_target'] ?: '_self') . '"' : '';
                    $hasChildren = !empty($navItem['children']);
                    ?>
                    <div class="relative<?php echo $hasChildren ? ' group' : ''; ?>">
                        <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?>
                           class="px-3 py-2 rounded-lg text-sm font-medium transition flex items-center gap-1 <?php echo $isActive ? 'text-white bg-white/5' : 'text-slate-400 hover:text-white hover:bg-white/5'; ?>">
                            <?php echo e($navItem['name']); ?>
                            <?php if ($hasChildren): ?>
                            <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            <?php endif; ?>
                        </a>
                        <?php if ($hasChildren): ?>
                        <!-- Flyout -->
                        <div class="absolute top-full left-0 mt-1 min-w-[12rem] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                            <div class="bg-slate-900/95 backdrop-blur-xl rounded-xl border border-slate-800 shadow-2xl shadow-black/40 p-1.5">
                                <?php foreach ($navItem['children'] as $child): ?>
                                <a href="<?php echo getChannelUrl($child); ?>"<?php echo $child['type'] === 'link' ? ' target="' . e($child['link_target'] ?: '_self') . '"' : ''; ?>
                                   class="block px-3 py-2 rounded-md text-sm text-slate-300 hover:text-white hover:bg-white/5 transition whitespace-nowrap">
                                    <?php echo e($child['name']); ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </nav>

                <!-- Right side: lang switcher + member + mobile hamburger -->
                <div class="flex items-center gap-2">
                    <?php if (config('show_lang_switcher', '0') === '1' && count(enabledLanguages()) > 1): ?>
                    <div class="relative hidden md:block" id="langSwitcher">
                        <button onclick="document.getElementById('langDropdown').classList.toggle('hidden')"
                                class="px-2.5 py-1.5 rounded-lg text-xs text-slate-400 border border-slate-800 hover:text-white hover:border-slate-600 transition inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                            <?php echo enabledLanguages()[siteLang()] ?? siteLang(); ?>
                        </button>
                        <div id="langDropdown" class="hidden absolute right-0 mt-1 bg-slate-900/95 backdrop-blur-xl rounded-lg border border-slate-800 shadow-2xl py-1 min-w-[110px] z-50">
                            <?php foreach (enabledLanguages() as $lk => $lv): ?>
                            <a href="javascript:switchLang('<?php echo $lk; ?>')" class="block px-3 py-2 text-xs hover:bg-white/5 transition <?php echo siteLang() === $lk ? 'text-white font-medium' : 'text-slate-400'; ?>"><?php echo e($lv); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <script>document.addEventListener('click',function(e){var s=document.getElementById('langSwitcher');if(s&&!s.contains(e.target))document.getElementById('langDropdown').classList.add('hidden')});</script>
                    <?php endif; ?>
                    <?php if ($showMemberEntry): ?>
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = $memberInfo ?? getMemberInfo(); ?>
                    <a href="/member/profile.php" class="hidden md:inline-block px-3 py-1.5 rounded-lg text-xs text-slate-300 border border-slate-800 hover:border-slate-600 hover:text-white transition">
                        <?php echo e($memberInfo['nickname']); ?>
                    </a>
                    <?php else: ?>
                    <a href="/member/login.php" class="hidden md:inline-flex items-center px-4 py-1.5 rounded-lg text-xs font-medium bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-lg shadow-indigo-500/30 hover:shadow-indigo-500/50 hover:-translate-y-0.5 transition-all">
                        登录
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <!-- Mobile Hamburger -->
                    <button id="mobileMenuBtn" class="md:hidden p-2 text-slate-300" aria-label="菜单">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <nav id="mobileMenu" class="md:hidden hidden border-t border-slate-800/60 bg-slate-950/95 backdrop-blur-xl">
            <div class="container mx-auto px-6 py-4 space-y-1">
                <?php if (configRawLang('nav_home_show', '1') !== '0'): ?>
                <a href="/" class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-white/5"><?php echo e(configLang('nav_home_text', 'nav_home')); ?></a>
                <?php endif; ?>
                <?php foreach ($navChannels as $navItem): ?>
                <a href="<?php echo getChannelUrl($navItem); ?>"
                   <?php echo $navItem['type'] === 'link' ? 'target="' . e($navItem['link_target'] ?: '_self') . '"' : ''; ?>
                   class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-white/5">
                    <?php echo e($navItem['name']); ?>
                </a>
                <?php if (!empty($navItem['children'])): ?>
                <?php echo renderNavMobileItems($navItem['children'], 0, '', 'block px-6 py-1.5 text-xs text-slate-500 hover:text-slate-200'); ?>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($showMemberEntry): ?>
                <div class="border-t border-slate-800/60 pt-3 mt-3">
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = $memberInfo ?? getMemberInfo(); ?>
                    <a href="/member/profile.php" class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-white/5"><?php echo e($memberInfo['nickname']); ?></a>
                    <a href="/member/logout.php" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-white hover:bg-white/5">退出登录</a>
                    <?php else: ?>
                    <a href="/member/login.php" class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-white/5">登录</a>
                    <?php if (config('allow_member_register') === '1'): ?>
                    <a href="/member/register.php" class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-white/5">注册</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <?php do_action('ik_header_after'); ?>

    <?php if (config('show_lang_switcher', '0') === '1'): ?>
    <script>
    function switchLang(lang) {
        var defaultLang = <?php echo json_encode((string)config('site_lang', 'zh-CN')); ?>;
        document.cookie = 'site_lang=' + lang + ';path=/;max-age=' + (365*86400);
        var path = location.pathname.replace(/^\/(ja|en|zh-CN)\//, '/');
        if (lang !== defaultLang) path = '/' + lang + path;
        location.href = path + location.search;
    }
    </script>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="flex-1">
