<?php
declare(strict_types=1);

$siteName = configRawLang('site_name', 'Yikai CMS');
$siteLogo = configRawLang('site_logo', '');
$siteKeywords = config('site_keywords', '');
$siteDescription = config('site_description', '');
$primaryColor = config('primary_color', '#3B6CF5');

$seoTitle = config('seo_title', '');
if (empty($pageTitle) && !empty($seoTitle)) {
    $fullTitle = $seoTitle;
} else {
    $fullTitle = !empty($pageTitle) ? $pageTitle . ' - ' . $siteName : $siteName;
}

$siteUrl = rtrim(config('site_url', SITE_URL), '/');
$canonicalUrl = $canonicalUrl ?? ($siteUrl . ($_SERVER['REQUEST_URI'] ?? '/'));
$ogImage = config('seo_og_image', '') ?: configRawLang('site_logo', '');
if ($ogImage && !str_starts_with($ogImage, 'http')) $ogImage = $siteUrl . $ogImage;

if (!isset($navChannels)) {
    $navChannels = getNavChannels();
}
$currentChannelId = $currentChannelId ?? 0;
$currentSlug = $currentSlug ?? '';

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

$isTransparentHeader = !empty($isHomePage);
?>
<!DOCTYPE html>
<html lang="<?php echo getLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="keywords" content="<?php echo e($pageKeywords ?? $siteKeywords); ?>">
    <meta name="description" content="<?php echo e($pageDescription ?? $siteDescription); ?>">
    <title><?php echo e($fullTitle); ?></title>
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <?php echo renderHreflangs(); ?>
    <link rel="icon" href="<?php echo e(config('site_favicon', '/favicon.ico')); ?>">
    <meta property="og:title" content="<?php echo e($fullTitle); ?>">
    <meta property="og:description" content="<?php echo e($pageDescription ?? $siteDescription); ?>">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
    :root { --color-primary: <?php echo $primaryColor; ?>; --color-secondary: <?php echo config('secondary_color', '#2554d4'); ?>; }
    .nav-transparent { background: transparent; position: absolute; top: 0; left: 0; right: 0; z-index: 50; }
    .nav-transparent a, .nav-transparent button { color: #fff; }
    .nav-transparent .nav-link:hover { color: rgba(255,255,255,0.7); }
    /* 下拉菜单始终白底 → 文字必须深色（否则白底白字看不见）；
       两条规则更具体（多一个层级）所以会覆盖上一行的 #fff */
    .nav-transparent .nav-dropdown-menu a,
    .nav-transparent .nav-submenu a { color: #4b5563; }
    .nav-transparent .nav-dropdown-menu a:hover,
    .nav-transparent .nav-submenu a:hover { color: var(--color-primary, #3B6CF5); }
    .nav-solid { background: #1e293b; position: sticky; top: 0; z-index: 50; }
    .nav-solid > div > div > nav > a,
    .nav-solid > div > div > nav > div > a { color: #d1d5db; }
    .nav-solid > div > div > nav > a:hover,
    .nav-solid > div > div > nav > div > a:hover { color: #fff; }
    /* 同理：solid 状态下也确保下拉菜单文字深色 */
    .nav-solid .nav-dropdown-menu a,
    .nav-solid .nav-submenu a { color: #4b5563; }
    .nav-solid .nav-dropdown-menu a:hover,
    .nav-solid .nav-submenu a:hover { color: var(--color-primary, #3B6CF5); }
    .hero-overlay { background: linear-gradient(to bottom, rgba(0,0,0,0.5), rgba(0,0,0,0.7)); }
    .section-dark { background: #1e293b; color: #e2e8f0; }
    .cta-gradient { background: linear-gradient(135deg, #3B6CF5, #2554d4); }
    </style>
    <?php if (!empty($extraCss)): ?><?php echo $extraCss; ?><?php endif; ?>
    <?php do_action('ik_head'); ?>
    <?php do_action('render_head'); ?>
    <?php echo config('custom_head_code', ''); ?>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">

    <!-- Navigation bar -->
    <header class="<?php echo $isTransparentHeader ? 'nav-transparent' : 'nav-solid shadow-lg'; ?> transition-all duration-300">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16 md:h-20">
                <!-- Logo -->
                <a href="/" class="flex items-center gap-2">
                    <?php if ($siteLogo): ?>
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="h-10 md:h-12">
                    <?php else: ?>
                    <span class="text-xl font-bold"><?php echo e($siteName); ?></span>
                    <?php endif; ?>
                </a>

                <!-- Desktop navigation -->
                <nav class="hidden md:flex items-center gap-1">
                    <?php if (configRawLang('nav_home_show', '1') !== '0'): ?>
                    <a href="/" class="nav-link px-4 py-2 text-sm font-medium transition <?php echo isset($isHomePage) && $isHomePage ? 'text-white font-bold' : ''; ?>">
                        <?php echo e(configLang('nav_home_text', 'nav_home')); ?>
                    </a>
                    <?php endif; ?>
                    <?php foreach ($navChannels as $navItem):
                        $hasChildren = !empty($navItem['children']);
                        $isActive = isChannelActive($navItem, $currentChannelId, $currentSlug);
                        $navUrl = getChannelUrl($navItem);
                    ?>
                    <?php if ($hasChildren): ?>
                    <!-- 改用 default 主题同款的 .nav-dropdown / .nav-dropdown-menu 钩子，
                         走 assets/css/style.css 里现成的纯 CSS hover 规则；
                         之前的 Tailwind `group-hover:opacity-100/visible` 没被编译进
                         tailwind.css，导致下拉永远 opacity-0 / invisible 的空白 bug。 -->
                    <div class="nav-dropdown">
                        <a href="<?php echo $navUrl; ?>" class="nav-link px-4 py-2 text-sm font-medium transition inline-flex items-center gap-1 <?php echo $isActive ? 'font-bold' : ''; ?>">
                            <?php echo e($navItem['name']); ?>
                            <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </a>
                        <div class="nav-dropdown-menu">
                            <?php echo renderNavDropdownItems($navItem['children']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="<?php echo $navUrl; ?>" class="nav-link px-4 py-2 text-sm font-medium transition <?php echo $isActive ? 'font-bold' : ''; ?>">
                        <?php echo e($navItem['name']); ?>
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- CTA button -->
                    <a href="/contact.html" class="ml-3 bg-primary hover:bg-secondary text-white px-5 py-2 rounded-full text-sm font-medium transition">
                        <?php echo __('detail_consult'); ?>
                    </a>
                </nav>

                <!-- mobile menu button -->
                <button id="mobileMenuBtn" class="md:hidden p-2 text-white" aria-label="<?php echo __('menu_label'); ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>

        <!-- Mobile menu -->
        <nav id="mobileMenu" class="md:hidden hidden bg-slate-800 border-t border-slate-700">
            <div class="container mx-auto px-4 py-4">
                <?php if (configRawLang('nav_home_show', '1') !== '0'): ?>
                <a href="/" class="block py-2 text-gray-300 hover:text-white"><?php echo e(configLang('nav_home_text', 'nav_home')); ?></a>
                <?php endif; ?>
                <?php foreach ($navChannels as $navItem): ?>
                <a href="<?php echo getChannelUrl($navItem); ?>" class="block py-2 text-gray-300 hover:text-white"><?php echo e($navItem['name']); ?></a>
                <?php if (!empty($navItem['children'])): ?>
                <div class="pl-4">
                    <?php echo renderNavMobileItems($navItem['children'], 0, '', 'block py-1.5 text-gray-400 hover:text-white text-sm'); ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </nav>
    </header>

    <?php do_action('ik_header_after'); ?>

    <!-- Main content -->
    <main class="flex-1">
