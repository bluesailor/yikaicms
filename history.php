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
$pageDescription = sprintf(__('history_page_description'), configRawLang('site_name'));
$isHistoryPage = true;

// 获取"关于我们"父栏目及子栏目（用于侧边栏）
$historyChannel = getChannelBySlug('history', true);
$aboutChannel = $historyChannel && !empty($historyChannel['parent_id'])
    ? getChannel((int) $historyChannel['parent_id'])
    : channelModel()->findBy('slug', 'about');
$sidebarChannels = [];
if ($aboutChannel) {
    $sidebarChannels = getChannels((int)$aboutChannel['id'], false);
}

if (!isCleanFrontendPreview() && !empty($_SESSION['admin_id']) && $historyChannel) {
    $GLOBALS['ik_edit_url'] = pagePrimaryEditUrl($historyChannel);
}
$GLOBALS['ykBloxPageId'] = (int) ($historyChannel['id'] ?? 0);

require_once theme_path('layouts/header.php');
?>

<!-- 页面头部 -->
<?php
$breadcrumbItems = [];
if ($aboutChannel) {
    $breadcrumbItems[] = ['name' => $aboutChannel['name'], 'url' => channelUrl($aboutChannel)];
}
$breadcrumbItems[] = ['name' => __('nav_history'), 'url' => ''];
// 标题/描述沿用语言包既有行为；横幅相关字段透传真实栏目行，
// 使 hero_bg/show_hero/栏目图 在发展历程页同样生效（此前合成数组把 image 写死为空）。
$channel = [
    'name' => __('nav_history'),
    'description' => __('history_hero_desc'),
    'image' => (string) ($historyChannel['image'] ?? ''),
    'hero_bg' => (string) ($historyChannel['hero_bg'] ?? ''),
    'show_hero' => (int) ($historyChannel['show_hero'] ?? 1),
];
require theme_path('partials/page-hero.php');
?>

<!-- 时间线主体 -->
<section class="py-16 bg-gray-50">
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
        <?php
        $rightSidebarTitle = (string) ($aboutChannel['name'] ?? __('nav_about'));
        $rightSidebarChannels = $sidebarChannels;
        $rightSidebarActiveId = (int) ($historyChannel['id'] ?? 0);
        require theme_path('partials/right_sidebar.php');
        ?>
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
require_once theme_path('layouts/footer.php');
?>
