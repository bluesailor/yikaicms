<?php
/**
 * Yikai CMS - 前台头部
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 获取站点配置
$siteName = config('site_name', 'Yikai CMS');
$siteLogo = config('site_logo', '');
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
$siteUrl = rtrim(config('site_url', SITE_URL), '/');
$canonicalUrl = $canonicalUrl ?? ($siteUrl . ($_SERVER['REQUEST_URI'] ?? '/'));
$ogType = $ogType ?? 'website';
$ogImage = $ogImage ?? config('seo_og_image', '') ?: config('site_logo', '');
if ($ogImage && !str_starts_with($ogImage, 'http')) {
    $ogImage = $siteUrl . $ogImage;
}
$ogDescription = $pageDescription ?? $siteDescription;

// 获取导航栏目（带子栏目）
if (!isset($navChannels)) {
    $navChannels = getNavChannels();
}

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

// 获取栏目链接（SEO友好URL）
function getChannelUrl(array $channel): string {
    // 动态注入的URL（如产品分类）
    if (!empty($channel['_url'])) {
        return $channel['_url'];
    }
    if ($channel['type'] === 'link') {
        return e($channel['link_url']);
    }

    $slug = $channel['slug'] ?? '';
    if (empty($slug)) {
        // 没有slug时使用id
        if ($channel['type'] === 'page') {
            return '/page/' . $channel['id'] . '.html';
        } else {
            return '/list/' . $channel['id'] . '.html';
        }
    }

    // 使用slug生成友好URL
    if ($channel['type'] === 'page') {
        // 单页：检查是否有父级
        if (!empty($channel['parent_id'])) {
            $parent = getChannel((int)$channel['parent_id']);
            if ($parent && !empty($parent['slug'])) {
                return '/' . $parent['slug'] . '/' . $slug . '.html';
            }
        }
        return '/' . $slug . '.html';
    } else {
        // 列表页
        return '/' . $slug . '.html';
    }
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
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>:root { --color-primary: <?php echo config('primary_color', '#3B82F6'); ?>; --color-secondary: <?php echo config('secondary_color', '#1D4ED8'); ?>; }</style>
    <?php if (!empty($extraCss)): ?>
    <?php echo $extraCss; ?>
    <?php endif; ?>
    <?php do_action('ik_head'); ?>
    <?php echo config('custom_head_code', ''); ?>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- 顶部通栏 -->
    <?php if ($topbarEnabled): ?>
    <div class="text-sm <?php echo $headerSticky === '1' ? 'sticky top-0' : ''; ?> z-50" style="background-color: <?php echo e($topbarBgColor); ?>">
        <div class="container mx-auto px-4 flex items-center justify-between h-8 text-gray-600">
            <div class="topbar-left text-xs"><?php echo $topbarLeft; ?></div>
            <div class="flex items-center gap-3">
                <?php if ($showMemberEntry): ?>
                <?php if (isMemberLoggedIn()): ?>
                <?php $memberInfo = getMemberInfo(); ?>
                <a href="/member/profile.php" class="hover:text-primary transition inline-flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <?php echo e($memberInfo['nickname']); ?>
                </a>
                <a href="/member/logout.php" class="hover:text-primary transition opacity-70">退出</a>
                <?php else: ?>
                <a href="/member/login.php" class="hover:text-primary transition">登录</a>
                <?php if (config('allow_member_register') === '1'): ?>
                <span class="text-gray-300">|</span>
                <a href="/member/register.php" class="hover:text-primary transition">注册</a>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 顶部导航 -->
    <header class="shadow-sm <?php echo $headerSticky === '1' ? ($topbarEnabled ? 'sticky top-8' : 'sticky top-0') : ''; ?> z-50" style="background-color: <?php echo e($headerBgColor); ?>">
        <?php if ($headerNavLayout === 'below'): ?>
        <!-- 布局：Logo上 + 导航下方通栏 -->
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-2">
                    <?php if ($siteLogo): ?>
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="h-10">
                    <?php else: ?>
                    <span class="text-xl font-bold text-primary"><?php echo e($siteName); ?></span>
                    <?php endif; ?>
                </a>
                <!-- 会员入口（导航栏模式） -->
                <?php if ($showMemberEntry && !$topbarEnabled): ?>
                <div class="hidden md:flex items-center gap-3 text-sm" style="color: <?php echo e($headerTextColor); ?>">
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = getMemberInfo(); ?>
                    <a href="/member/profile.php" class="hover:text-primary transition"><?php echo e($memberInfo['nickname']); ?></a>
                    <a href="/member/logout.php" class="hover:text-primary transition opacity-60">退出</a>
                    <?php else: ?>
                    <a href="/member/login.php" class="hover:text-primary transition">登录</a>
                    <?php if (config('allow_member_register') === '1'): ?>
                    <a href="/member/register.php" class="hover:text-primary transition">注册</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <button id="mobileMenuBtn" class="md:hidden p-2" style="color: <?php echo e($headerTextColor); ?>" aria-label="菜单">
                    <div class="hamburger" id="hamburgerIcon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </button>
            </div>
        </div>
        <nav class="hidden md:block border-t" style="border-color: rgba(0,0,0,0.06)">
            <div class="container mx-auto px-4">
                <div class="flex items-center gap-1">
                    <?php if (config('nav_home_show', '1') !== '0'): ?>
                    <a href="/" class="px-4 py-3 hover:text-primary transition <?php echo isset($isHomePage) && $isHomePage ? 'text-primary font-medium' : ''; ?>" style="color: <?php echo isset($isHomePage) && $isHomePage ? '' : e($headerTextColor); ?>">
                        <?php echo e(config('nav_home_text', '') ?: __('nav_home')); ?>
                    </a>
                    <?php endif; ?>
                    <?php foreach ($navChannels as $navItem): ?>
                    <?php
                    $hasChildren = !empty($navItem['children']);
                    $isActive = isChannelActive($navItem, $currentChannelId, $currentSlug);
                    $navUrl = getChannelUrl($navItem);
                    $linkTarget = $navItem['type'] === 'link' ? ' target="' . e($navItem['link_target'] ?: '_self') . '"' : '';
                    ?>
                    <?php if ($hasChildren): ?>
                    <div class="nav-dropdown">
                        <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?>
                           class="flex items-center gap-1 px-4 py-3 hover:text-primary transition <?php echo $isActive ? 'text-primary font-medium' : ''; ?>" style="color: <?php echo $isActive ? '' : e($headerTextColor); ?>">
                            <?php echo e($navItem['name']); ?>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </a>
                        <div class="nav-dropdown-menu">
                            <?php echo renderNavDropdownItems($navItem['children']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?>
                       class="px-4 py-3 hover:text-primary transition <?php echo $isActive ? 'text-primary font-medium' : ''; ?>" style="color: <?php echo $isActive ? '' : e($headerTextColor); ?>">
                        <?php echo e($navItem['name']); ?>
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>
        <?php else: ?>
        <!-- 布局：Logo左 + 导航右（默认） -->
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-2">
                    <?php if ($siteLogo): ?>
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="h-10">
                    <?php else: ?>
                    <span class="text-xl font-bold text-primary"><?php echo e($siteName); ?></span>
                    <?php endif; ?>
                </a>
                <nav class="hidden md:flex items-center gap-1">
                    <?php if (config('nav_home_show', '1') !== '0'): ?>
                    <a href="/" class="px-4 py-2 hover:text-primary transition <?php echo isset($isHomePage) && $isHomePage ? 'text-primary font-medium' : ''; ?>" style="color: <?php echo isset($isHomePage) && $isHomePage ? '' : e($headerTextColor); ?>">
                        <?php echo e(config('nav_home_text', '') ?: __('nav_home')); ?>
                    </a>
                    <?php endif; ?>
                    <?php foreach ($navChannels as $navItem): ?>
                    <?php
                    $hasChildren = !empty($navItem['children']);
                    $isActive = isChannelActive($navItem, $currentChannelId, $currentSlug);
                    $navUrl = getChannelUrl($navItem);
                    $linkTarget = $navItem['type'] === 'link' ? ' target="' . e($navItem['link_target'] ?: '_self') . '"' : '';
                    ?>
                    <?php if ($hasChildren): ?>
                    <div class="nav-dropdown">
                        <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?>
                           class="flex items-center gap-1 px-4 py-2 hover:text-primary transition <?php echo $isActive ? 'text-primary font-medium' : ''; ?>" style="color: <?php echo $isActive ? '' : e($headerTextColor); ?>">
                            <?php echo e($navItem['name']); ?>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </a>
                        <div class="nav-dropdown-menu">
                            <?php echo renderNavDropdownItems($navItem['children']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="<?php echo $navUrl; ?>"<?php echo $linkTarget; ?>
                       class="px-4 py-2 hover:text-primary transition <?php echo $isActive ? 'text-primary font-medium' : ''; ?>" style="color: <?php echo $isActive ? '' : e($headerTextColor); ?>">
                        <?php echo e($navItem['name']); ?>
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <!-- 会员入口（导航栏模式） -->
                    <?php if ($showMemberEntry && !$topbarEnabled): ?>
                    <span class="w-px h-4 bg-gray-300 mx-1"></span>
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = $memberInfo ?? getMemberInfo(); ?>
                    <a href="/member/profile.php" class="px-3 py-2 hover:text-primary transition text-sm" style="color: <?php echo e($headerTextColor); ?>"><?php echo e($memberInfo['nickname']); ?></a>
                    <a href="/member/logout.php" class="px-2 py-2 hover:text-primary transition text-sm opacity-60" style="color: <?php echo e($headerTextColor); ?>">退出</a>
                    <?php else: ?>
                    <a href="/member/login.php" class="px-3 py-2 hover:text-primary transition text-sm" style="color: <?php echo e($headerTextColor); ?>">登录</a>
                    <?php if (config('allow_member_register') === '1'): ?>
                    <a href="/member/register.php" class="px-2 py-2 hover:text-primary transition text-sm" style="color: <?php echo e($headerTextColor); ?>">注册</a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </nav>
                <button id="mobileMenuBtn" class="md:hidden p-2" style="color: <?php echo e($headerTextColor); ?>" aria-label="菜单">
                    <div class="hamburger" id="hamburgerIcon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- 移动端菜单 -->
        <nav id="mobileMenu" class="md:hidden hidden border-t" style="background-color: <?php echo e($headerBgColor); ?>">
            <div class="container mx-auto px-4 py-4">
                <?php if (config('nav_home_show', '1') !== '0'): ?>
                <a href="/" class="block py-2 hover:text-primary" style="color: <?php echo e($headerTextColor); ?>"><?php echo e(config('nav_home_text', '') ?: __('nav_home')); ?></a>
                <?php endif; ?>
                <?php foreach ($navChannels as $navItem): ?>
                <?php $hasChildren = !empty($navItem['children']); ?>
                <div class="<?php echo $hasChildren ? 'border-b border-gray-100 pb-2 mb-2' : ''; ?>">
                    <a href="<?php echo getChannelUrl($navItem); ?>"
                       <?php echo $navItem['type'] === 'link' ? 'target="' . e($navItem['link_target'] ?: '_self') . '"' : ''; ?>
                       class="block py-2 hover:text-primary font-medium" style="color: <?php echo e($headerTextColor); ?>">
                        <?php echo e($navItem['name']); ?>
                    </a>
                    <?php if ($hasChildren): ?>
                    <div class="pl-4">
                        <?php echo renderNavMobileItems($navItem['children'], 0, $headerTextColor); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <!-- 移动端会员入口 -->
                <?php if ($showMemberEntry): ?>
                <div class="border-t border-gray-100 pt-2 mt-2">
                    <?php if (isMemberLoggedIn()): ?>
                    <?php $memberInfo = $memberInfo ?? getMemberInfo(); ?>
                    <a href="/member/profile.php" class="block py-2 hover:text-primary" style="color: <?php echo e($headerTextColor); ?>">会员中心 (<?php echo e($memberInfo['nickname']); ?>)</a>
                    <a href="/member/logout.php" class="block py-2 hover:text-primary" style="color: <?php echo e($headerTextColor); ?>; opacity: 0.7">退出登录</a>
                    <?php else: ?>
                    <a href="/member/login.php" class="block py-2 hover:text-primary" style="color: <?php echo e($headerTextColor); ?>">会员登录</a>
                    <?php if (config('allow_member_register') === '1'): ?>
                    <a href="/member/register.php" class="block py-2 hover:text-primary" style="color: <?php echo e($headerTextColor); ?>">会员注册</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <?php do_action('ik_header_after'); ?>

    <!-- 主内容 -->
    <main class="flex-1">
