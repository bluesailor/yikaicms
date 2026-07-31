<?php
/**
 * Blox 实验主题 - 头部（干净画布底座）
 *
 * 极简 chrome：细导航条 + 主体容器，把舞台留给构建器区块。
 * 必需钩子齐全（ik_head / ik_header_after），前台就地编辑覆盖层与插件正常工作。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

$siteName = configRawLang('site_name', 'Yikai CMS');
$siteLogo = configRawLang('site_logo', '');
$siteKeywords = configJsonLang('site_keywords') ?: config('site_keywords', '');
$siteDescription = configJsonLang('site_description') ?: config('site_description', '');

$seoTitle = configJsonLang('seo_title') ?: config('seo_title', '');
$fullTitle = !empty($pageTitle) ? $pageTitle . ' - ' . $siteName : ($seoTitle ?: $siteName);

$siteUrl = siteBaseUrl();
$canonicalUrl = $canonicalUrl ?? ($siteUrl . ($_SERVER['REQUEST_URI'] ?? '/'));
$pageDescription = $pageDescription ?? $siteDescription;

if (!isset($navChannels)) {
    $navChannels = getNavChannels();
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', siteLang())); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($fullTitle); ?></title>
    <?php if ($pageDescription): ?><meta name="description" content="<?php echo e($pageDescription); ?>"><?php endif; ?>
    <?php if ($siteKeywords): ?><meta name="keywords" content="<?php echo e($siteKeywords); ?>"><?php endif; ?>
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <!-- JSON-LD 结构化数据（与默认主题一致：Organization + 页面级 $jsonLd） -->
    <?php $ldLogo = $siteLogo ? (preg_match('#^https?://#', $siteLogo) ? $siteLogo : rtrim($siteUrl, '/') . '/' . ltrim($siteLogo, '/')) : null; ?>
    <script type="application/ld+json">
    <?php echo json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => $siteName,
        'url'      => $siteUrl,
        'logo'     => $ldLogo,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    </script>
    <?php if (!empty($jsonLd)): ?>
    <script type="application/ld+json">
    <?php echo json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    </script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo assetVer('/assets/css/tailwind.css'); ?>">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <link rel="icon" href="<?php echo e(config('site_favicon', '/favicon.ico')); ?>">
    <style>
        :root { --color-primary: <?php echo e(config('primary_color', '#2563eb')); ?>; --color-secondary: <?php echo e(config('secondary_color', '#1d4ed8')); ?>; }
        .blk-title { font-size: 1.875rem; font-weight: 700; }
        .section-title-bar { display: inline-block; width: 48px; height: 3px; background: var(--color-primary); border-radius: 2px; }
        .blk-sub { color: #6b7280; margin-top: .5rem; }
    </style>
    <?php if (!empty($extraCss)): ?>
    <?php echo $extraCss; ?>
    <?php endif; ?>
    <?php do_action('ik_head'); ?>
    <?php do_action('render_head'); ?>
    <?php echo config('custom_head_code', ''); ?>
</head>
<body class="bg-white text-gray-800 antialiased">
    <!-- 极简顶栏：Logo / 站名 + 导航 -->
    <header class="border-b border-gray-100 sticky top-0 z-40 bg-white/90 backdrop-blur">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2 font-bold text-lg" style="color:var(--color-primary)">
                <?php if ($siteLogo): ?>
                <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="h-8">
                <?php else: ?>
                <span><?php echo e($siteName); ?></span>
                <?php endif; ?>
            </a>
            <nav class="hidden md:flex items-center gap-6 text-sm">
                <?php foreach ($navChannels as $navItem): ?>
                <a href="<?php echo e(channelUrl($navItem)); ?>" class="text-gray-600 hover:text-gray-900 transition"><?php echo e($navItem['name']); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>
    <?php do_action('ik_header_after'); ?>
    <main>
