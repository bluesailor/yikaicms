<?php
/**
 * Yikai CMS - 发展历程页面
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// 后台预览模式：跳过 HtmlCache，确保切换布局后立即看到效果
$isPreview = !empty($_GET['_preview']);
if (!$isPreview) {
    HtmlCache::start(600);
}

// 时间线数据（仅用于本页面"统计数据"区块；时间线本体已交给 timelineBlock 渲染）
$timelines = timelineModel()->getActive();
$timelineYears = [];
foreach ($timelines as $it) $timelineYears[(int)$it['year']] = true;
ksort($timelineYears);

$pageTitle = __('nav_history');
$pageDescription = sprintf(__('history_page_description'), config('site_name'));
$isHistoryPage = true;

// 获取"关于我们"父栏目及子栏目（用于侧边栏）
$aboutChannel = channelModel()->findBy('slug', 'about');
$sidebarChannels = [];
if ($aboutChannel) {
    $sidebarChannels = getChannels((int)$aboutChannel['id'], false);
}

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- 页面头部 -->
<?php
$breadcrumbItems = [];
if ($aboutChannel) {
    $breadcrumbItems[] = ['name' => $aboutChannel['name'], 'url' => channelUrl($aboutChannel)];
}
$breadcrumbItems[] = ['name' => __('nav_history'), 'url' => ''];
$channel = ['name' => __('nav_history'), 'description' => __('history_hero_desc'), 'image' => ''];
require theme_path('partials/page-hero.php');
?>

<!-- 时间线主体 -->
<section class="py-16 bg-gradient-to-b from-gray-50 to-white">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
        <!-- 主内容区 -->
        <div class="w-full lg:flex-1">
        <?php if (empty($timelines)): ?>
        <div class="text-center py-20 text-gray-500">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p><?php echo __('history_empty'); ?></p>
        </div>
        <?php else: ?>

        <!-- 时间线容器（统一走 timelineBlock，可被短码 [timeline] 复用） -->
        <?php
        // 预览模式下，URL 参数 ?layout=xxx 可临时覆盖（仅 _preview=1 时生效）
        $previewLayout = $isPreview && in_array($_GET['layout'] ?? '', ['vertical', 'horizontal', 'compact'], true)
            ? $_GET['layout']
            : null;
        echo timelineBlock($previewLayout ? ['layout' => $previewLayout] : []);
        ?>

        <?php endif; ?>
        </div>

        <!-- 侧边栏 -->
        <?php if (!empty($sidebarChannels)): ?>
        <div class="w-full lg:w-72 flex-shrink-0">
            <div class="bg-white rounded-lg shadow sticky top-24">
                <div class="px-4 py-3 border-b font-bold text-dark bg-primary text-white rounded-t-lg">
                    <?php echo e($aboutChannel['name'] ?? '关于我们'); ?>
                </div>
                <div class="divide-y">
                    <?php foreach ($sidebarChannels as $sub): ?>
                    <a href="<?php echo channelUrl($sub); ?>"
                       class="block px-4 py-3 hover:bg-gray-50 transition <?php echo ($sub['slug'] ?? '') === 'history' ? 'text-primary bg-blue-50 font-medium' : 'text-gray-700 hover:text-primary'; ?>">
                        <?php echo e($sub['name']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 联系方式 -->
            <div class="bg-white rounded-lg shadow mt-6">
                <div class="px-4 py-3 border-b font-bold text-dark"><?php echo __('footer_contact'); ?></div>
                <div class="p-4 space-y-3 text-sm">
                    <?php if ($phone = config('contact_phone')): ?>
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                        </svg>
                        <span><?php echo e($phone); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($email = config('contact_email')): ?>
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <span><?php echo e($email); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($address = config('contact_address')): ?>
                    <div class="flex items-start gap-2">
                        <svg class="w-4 h-4 text-primary mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        </svg>
                        <span><?php echo e($address); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</section>

<!-- 统计数据 -->
<?php if (!empty($timelines)): ?>
<section class="py-16 bg-gray-900 text-white">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
            <div data-aos="fade-up" data-aos-delay="0">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo count($timelineYears); ?>+
                </div>
                <div class="text-gray-400"><?php echo __('history_stats_years'); ?></div>
            </div>
            <div data-aos="fade-up" data-aos-delay="100">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo count($timelines); ?>+
                </div>
                <div class="text-gray-400"><?php echo __('history_stats_milestones'); ?></div>
            </div>
            <div data-aos="fade-up" data-aos-delay="200">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo (int)array_key_first($timelineYears); ?>
                </div>
                <div class="text-gray-400"><?php echo __('history_stats_founded'); ?></div>
            </div>
            <div data-aos="fade-up" data-aos-delay="300">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo date('Y') - (int)array_key_first($timelineYears); ?>+
                </div>
                <div class="text-gray-400"><?php echo __('history_stats_experience'); ?></div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- 轻量 scroll-anim（替代 AOS） -->
<script src="/assets/js/scroll-anim.js" defer></script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>
