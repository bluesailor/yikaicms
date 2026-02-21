<?php
/**
 * Yikai CMS - 联系我们
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// 加载栏目数据
$channel = getChannelBySlug('contact');
$currentChannelId = $channel ? (int)$channel['id'] : 0;

// 页面信息（优先使用栏目SEO设置）
$pageTitle = ($channel && $channel['seo_title']) ? $channel['seo_title'] : __('contact_title');
$pageKeywords = ($channel && $channel['seo_keywords']) ? $channel['seo_keywords'] : config('site_keywords');
$pageDescription = ($channel && $channel['seo_description']) ? $channel['seo_description'] : config('site_description');

// 获取导航
$navChannels = getNavChannels();

// 图标SVG路径映射
$iconPaths = [
    'phone'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>',
    'email'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>',
    'location' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>',
    'clock'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
    'fax'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>',
    'wechat'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
    'building' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>',
    'globe'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>',
    'qq'       => '<g transform="scale(0.0234375)"><path fill="currentColor" stroke="none" d="M512.4096 77.7728c-232.0896 0-420.864 188.8256-420.864 420.864 0 232.0896 188.8256 420.864 420.864 420.864 232.0896 0 420.864-188.8256 420.864-420.864s-188.7744-420.864-420.864-420.864z m0 800.8192c-209.5104 0-379.904-170.4448-379.904-379.904 0-209.5104 170.4448-379.904 379.904-379.904 209.5104 0 379.904 170.4448 379.904 379.904 0 209.4592-170.3936 379.904-379.904 379.904z"></path><path fill="currentColor" stroke="none" d="M683.9296 485.376c-1.8944-2.048-3.1232-5.4272-3.2768-8.2432-0.4096-8.448 0.2048-16.9472-0.256-25.3952-0.5632-10.1376-2.7136-20.6336-10.0352-27.5456-6.144-5.7856-6.8608-11.9296-7.168-19.0464-0.1024-2.56-0.3584-5.0688-0.6144-7.6288-3.9424-34.3552-13.0048-66.9184-36.4032-93.44-36.4544-41.3184-83.1488-57.1392-136.9088-49.2032-47.0528 6.912-84.1216 30.464-107.1616 73.4208-13.5168 25.1904-19.0464 52.5312-20.736 80.7424-0.3072 5.4272-0.8192 10.0352-5.888 13.6704-2.7136 1.9456-4.352 5.6832-5.8368 8.9088-6.5024 13.9264-6.656 28.7744-5.376 43.6736 0.3584 4.5056-0.8192 7.8336-4.0448 11.3152-24.6784 26.4704-42.1888 56.9344-48.384 92.9792-2.5088 14.5408-1.2288 29.2864 4.608 43.1104 3.3792 7.9872 9.216 9.9328 15.616 4.3008 7.2704-6.3488 13.4144-13.9776 19.7632-21.3504 2.9696-3.4816 5.1712-7.5776 7.3216-10.8032 11.5712 21.0944 23.0912 42.0352 34.2528 62.3104-7.2704 5.376-16.2304 10.5472-23.296 17.5616-15.36 15.1552-20.8384 45.6704 10.8544 60.1088 2.8672 1.3312 5.8368 2.56 8.9088 3.4304 29.2864 8.3968 58.5728 7.9872 87.9616 0.2048 15.5136-4.096 30.5152-9.0624 42.1888-20.8896 5.8368-5.9392 18.688-5.3248 25.1904-0.1024 6.5024 5.2736 13.4144 10.6496 21.0432 13.8752 22.3232 9.5232 45.824 13.9776 70.144 12.9536 16.7936-0.7168 33.4848-2.4064 48.896-10.0352 15.2064-7.5264 22.6816-19.2 21.5552-33.5872-1.024-13.3632-7.5264-23.808-17.9712-31.6416-5.888-4.4032-12.3904-7.936-17.6128-11.264 11.4688-20.8896 22.9888-41.8304 34.56-62.8736 2.1504 3.2256 4.4032 7.3216 7.3216 10.8544 5.7856 6.9632 11.4176 14.336 18.176 20.2752 7.9872 7.0144 14.1312 4.9664 17.9712-5.0688 4.9152-12.9536 6.2976-26.7776 4.0448-40.1408-6.3488-36.9664-24.3712-68.096-49.408-95.4368z"></path></g>',
];

// 从设置读取联系信息卡片
$contactCards = json_decode(config('contact_cards') ?: '[]', true) ?: [];

// 根据数量决定列数
$gridCols = match (count($contactCards)) {
    1 => 'md:grid-cols-1',
    2 => 'md:grid-cols-2',
    4 => 'md:grid-cols-2 lg:grid-cols-4',
    default => 'md:grid-cols-3',
};

// 引入头部
require_once INCLUDES_PATH . 'header.php';
?>

<!-- 页面头部 -->
<?php if ($channel && $channel['image']): ?>
<section class="relative py-16 bg-cover bg-center" style="background-image: url('<?php echo e($channel['image']); ?>')">
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="container mx-auto px-4 relative">
        <div class="flex items-center gap-2 text-sm text-gray-300 mb-6">
            <a href="/" class="hover:text-white"><?php echo __('breadcrumb_home'); ?></a>
            <span>/</span>
            <span class="text-white"><?php echo __('contact_title'); ?></span>
        </div>
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo $channel ? e($channel['name']) : __('contact_title'); ?></h1>
            <?php if ($channel && $channel['description']): ?>
            <p class="text-gray-200 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php else: ?>
            <p class="text-gray-200 text-lg max-w-2xl mx-auto"><?php echo __('contact_subtitle'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<section class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 py-16 relative overflow-hidden">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-secondary rounded-full blur-3xl"></div>
    </div>
    <div class="container mx-auto px-4 relative">
        <div class="flex items-center gap-2 text-sm text-gray-400 mb-6">
            <a href="/" class="hover:text-white"><?php echo __('breadcrumb_home'); ?></a>
            <span>/</span>
            <span class="text-white"><?php echo __('contact_title'); ?></span>
        </div>
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo $channel ? e($channel['name']) : __('contact_title'); ?></h1>
            <?php if ($channel && $channel['description']): ?>
            <p class="text-gray-300 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php else: ?>
            <p class="text-gray-300 text-lg max-w-2xl mx-auto"><?php echo __('contact_subtitle'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="py-12">
    <div class="container mx-auto px-4">
        <!-- 联系信息卡片 -->
        <?php if (!empty($contactCards)): ?>
        <div class="grid grid-cols-1 <?php echo $gridCols; ?> gap-6 mb-12">
            <?php foreach ($contactCards as $card): ?>
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <?php if (!empty($card['icon']) && isset($iconPaths[$card['icon']])): ?>
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php echo $iconPaths[$card['icon']]; ?>
                    </svg>
                </div>
                <?php endif; ?>
                <h3 class="font-bold text-dark mb-2"><?php echo e($card['label']); ?></h3>
                <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i', $card['value'])): ?>
                <img src="<?php echo e($card['value']); ?>" alt="<?php echo e($card['label']); ?>" class="max-h-24 mx-auto">
                <?php else: ?>
                <p class="text-gray-600"><?php echo nl2br(e($card['value'])); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- 留言表单 -->
            <div class="bg-white rounded-lg shadow p-6 md:p-8">
                <?php $formTitle = config('contact_form_title') ?: __('contact_form_title'); ?>
                <h2 class="text-xl font-bold text-dark mb-2"><?php echo e($formTitle); ?></h2>
                <?php if ($formDesc = config('contact_form_desc')): ?>
                <p class="text-gray-500 text-sm mb-6"><?php echo e($formDesc); ?></p>
                <?php else: ?>
                <div class="mb-4"></div>
                <?php endif; ?>

                <?php echo renderFormTemplate('contact'); ?>
            </div>

            <!-- 地图或二维码 -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <?php if ($mapImage = config('contact_map')): ?>
                <img src="<?php echo e($mapImage); ?>" alt="地图" class="w-full h-full object-cover">
                <?php elseif ($qrcode = config('contact_qrcode')): ?>
                <div class="h-full flex flex-col items-center justify-center p-8">
                    <h3 class="text-xl font-bold text-dark mb-6">扫码联系我们</h3>
                    <img src="<?php echo e($qrcode); ?>" alt="二维码" class="w-48 h-48">
                    <p class="text-gray-500 mt-4">微信扫一扫</p>
                </div>
                <?php else: ?>
                <div class="h-full flex items-center justify-center bg-gray-100 min-h-[400px]">
                    <div class="text-center text-gray-400">
                        <svg class="w-16 h-16 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
                        </svg>
                        <p>可在后台设置地图图片</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once INCLUDES_PATH . 'footer.php'; ?>
