<?php
/**
 * Yikai CMS - 首页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// 页面信息
$isHomePage = true;
$pageTitle = '';
$pageKeywords = config('site_keywords');
$pageDescription = config('site_description');

// 获取首页数据
$banners = getBanners('home', 5);
$navChannels = getNavChannels();

// 获取首页展示的栏目（is_home=1）
$homeChannels = channelModel()->getHomeChannels();

// 获取产品分类（用于首页产品区块）
$productCategories = productCategoryModel()->getTopLevel(6);

// 为每个栏目获取内容，并构建 ID => channel 映射
$homeChannelsMap = [];
foreach ($homeChannels as &$hChannel) {
    if ($hChannel['type'] === 'product') {
        // 产品类型：从产品表获取
        $hChannel['contents'] = getProducts(0, 8, 0, ['is_recommend' => true]);
        $hChannel['is_product'] = true;
        $hChannel['categories'] = $productCategories;
    } elseif ($hChannel['slug'] === 'news') {
        // 新闻资讯：从文章表获取
        $newsParent = articleCategoryModel()->findBySlug('news');
        if ($newsParent) {
            $catIds = articleCategoryModel()->getChildIds((int)$newsParent['id']);
            $hChannel['contents'] = articleModel()->getByCategoryIds($catIds, 6);
        } else {
            $hChannel['contents'] = [];
        }
        $hChannel['is_product'] = false;
        $hChannel['is_article'] = true;
    } else {
        // 其他类型：从内容表获取
        $hChannel['contents'] = getContents((int)$hChannel['id'], 6, 0, ['include_children' => true]);
        $hChannel['is_product'] = false;
    }
    $homeChannelsMap[(int)$hChannel['id']] = $hChannel;
}
unset($hChannel);

// 获取关于我们栏目（用于简介区块）
$aboutChannel = getChannelBySlug('about');

// 轮播图高度配置
$bannerHeightPC = (int)config('banner_height_pc', 650);
$bannerHeightMobile = (int)config('banner_height_mobile', 300);

// 主题色
$primaryColor = config('primary_color', '#3B82F6');

// 客户评价数据
$testimonials = json_decode(config('home_testimonials', '[]'), true) ?: [];

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

// 区块模板映射
$blockTemplates = [
    'banner'       => INCLUDES_PATH . 'blocks/banner.php',
    'about'        => INCLUDES_PATH . 'blocks/about.php',
    'stats'        => INCLUDES_PATH . 'blocks/stats.php',
    'testimonials' => INCLUDES_PATH . 'blocks/testimonials.php',
    'advantage'    => INCLUDES_PATH . 'blocks/advantage.php',
    'cta'          => INCLUDES_PATH . 'blocks/cta.php',
];

// Swiper轮播图资源
$extraCss = '
<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">
<style>
.banner-swiper { height: ' . $bannerHeightMobile . 'px; }
@media (min-width: 768px) { .banner-swiper { height: ' . $bannerHeightPC . 'px; } }
.banner-swiper .swiper-pagination-bullet-active { opacity: 1; background: ' . $primaryColor . '; width: 24px; border-radius: 6px; }
</style>';

$extraJs = '
<script src="/assets/swiper/swiper-bundle.min.js"></script>
<script>
new Swiper(".banner-swiper", {
    loop: true,
    autoplay: { delay: 5000, disableOnInteraction: false },
    effect: "fade",
    fadeEffect: { crossFade: true },
    pagination: { el: ".swiper-pagination", clickable: true },
    navigation: { nextEl: ".swiper-button-next", prevEl: ".swiper-button-prev" }
});

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
require_once INCLUDES_PATH . 'header.php';

// 动态渲染首页区块
foreach ($blocksConfig as $block) {
    if (empty($block['enabled'])) continue;
    $type = $block['type'] ?? '';

    if (str_starts_with($type, 'channel:')) {
        // 独立栏目区块
        $channelId = (int)substr($type, 8);
        $currentChannel = $homeChannelsMap[$channelId] ?? null;
        if ($currentChannel) {
            require INCLUDES_PATH . 'blocks/channel.php';
        }
    } elseif (isset($blockTemplates[$type]) && file_exists($blockTemplates[$type])) {
        require $blockTemplates[$type];
    }
}

require_once INCLUDES_PATH . 'footer.php';
?>
