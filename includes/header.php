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
$fullTitle = !empty($pageTitle) ? $pageTitle . ' - ' . $siteName : $siteName;

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
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="keywords" content="<?php echo e($pageKeywords ?? $siteKeywords); ?>">
    <meta name="description" content="<?php echo e($pageDescription ?? $siteDescription); ?>">
    <title><?php echo e($fullTitle); ?></title>
    <link rel="icon" href="<?php echo e(config('site_favicon', '/favicon.ico')); ?>">
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
                            <?php foreach ($navItem['children'] as $child): ?>
                            <a href="<?php echo getChannelUrl($child); ?>"
                               <?php echo $child['type'] === 'link' ? 'target="' . e($child['link_target'] ?: '_self') . '"' : ''; ?>>
                                <?php echo e($child['name']); ?>
                            </a>
                            <?php endforeach; ?>
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
                            <?php foreach ($navItem['children'] as $child): ?>
                            <a href="<?php echo getChannelUrl($child); ?>"
                               <?php echo $child['type'] === 'link' ? 'target="' . e($child['link_target'] ?: '_self') . '"' : ''; ?>>
                                <?php echo e($child['name']); ?>
                            </a>
                            <?php endforeach; ?>
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
                        <?php foreach ($navItem['children'] as $child): ?>
                        <a href="<?php echo getChannelUrl($child); ?>"
                           <?php echo $child['type'] === 'link' ? 'target="' . e($child['link_target'] ?: '_self') . '"' : ''; ?>
                           class="block py-2 hover:text-primary text-sm" style="color: <?php echo e($headerTextColor); ?>; opacity: 0.8">
                            <?php echo e($child['name']); ?>
                        </a>
                        <?php endforeach; ?>
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
