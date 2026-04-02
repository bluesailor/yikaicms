<?php
/**
 * Yikai CMS - 发展历程页面
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// 图标辅助函数
function getTimelineIcon(string $icon): string {
    $icons = [
        'flag' => '🚩',
        'rocket' => '🚀',
        'award' => '🏆',
        'users' => '👥',
        'box' => '📦',
        'trending-up' => '📈',
        'map' => '🗺️',
        'handshake' => '🤝',
        'building' => '🏢',
        'star' => '⭐',
        'heart' => '❤️',
        'zap' => '⚡',
        'target' => '🎯',
        'globe' => '🌍',
    ];
    return $icons[$icon] ?? '';
}

// 获取时间线数据
$timelines = timelineModel()->getActive();

// 按年份分组
$groupedTimelines = [];
foreach ($timelines as $item) {
    $year = $item['year'];
    if (!isset($groupedTimelines[$year])) {
        $groupedTimelines[$year] = [];
    }
    $groupedTimelines[$year][] = $item;
}

$pageTitle = __('nav_history');
$pageDescription = config('site_name') . '的发展历程，记录我们成长的每一个重要时刻。';
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
$channel = ['name' => __('nav_history'), 'description' => '记录我们成长的每一个重要时刻，见证从创立到辉煌的蜕变历程', 'image' => ''];
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
            <p>暂无发展历程内容</p>
        </div>
        <?php else: ?>

        <!-- 时间线容器 -->
        <div class="timeline-container max-w-5xl mx-auto">
            <?php
            $index = 0;
            foreach ($groupedTimelines as $year => $events):
            ?>

            <!-- 年份标记 -->
            <div class="timeline-year flex items-center justify-center my-8" data-aos="fade-up">
                <div class="flex-1 h-px bg-gradient-to-r from-transparent to-gray-300"></div>
                <div class="mx-4 px-6 py-2 bg-gradient-to-r from-primary to-secondary text-white font-bold text-xl rounded-full shadow-lg">
                    <?php echo $year; ?>
                </div>
                <div class="flex-1 h-px bg-gradient-to-l from-transparent to-gray-300"></div>
            </div>

            <?php foreach ($events as $event):
                $isLeft = $index % 2 === 0;
                $colorClass = match($event['color']) {
                    'blue' => 'from-blue-500 to-blue-600',
                    'green' => 'from-green-500 to-green-600',
                    'yellow' => 'from-yellow-500 to-yellow-600',
                    'red' => 'from-red-500 to-red-600',
                    'purple' => 'from-purple-500 to-purple-600',
                    'cyan' => 'from-cyan-500 to-cyan-600',
                    'indigo' => 'from-indigo-500 to-indigo-600',
                    'pink' => 'from-pink-500 to-pink-600',
                    'gray' => 'from-gray-500 to-gray-600',
                    default => 'from-primary to-secondary',
                };
                $dotColor = match($event['color']) {
                    'blue' => 'bg-blue-500',
                    'green' => 'bg-green-500',
                    'yellow' => 'bg-yellow-500',
                    'red' => 'bg-red-500',
                    'purple' => 'bg-purple-500',
                    'cyan' => 'bg-cyan-500',
                    'indigo' => 'bg-indigo-500',
                    'pink' => 'bg-pink-500',
                    'gray' => 'bg-gray-500',
                    default => 'bg-primary',
                };
            ?>

            <!-- 时间线事件 -->
            <div class="timeline-item relative flex items-center justify-center mb-8"
                 data-aos="<?php echo $isLeft ? 'fade-right' : 'fade-left'; ?>"
                 data-aos-delay="<?php echo ($index % 3) * 100; ?>">

                <!-- 中间的连接线 -->
                <div class="hidden md:block absolute left-1/2 -translate-x-1/2 w-1 h-full bg-gradient-to-b from-gray-200 to-gray-300 -z-10"></div>

                <!-- 中间的圆点 -->
                <div class="hidden md:flex absolute left-1/2 -translate-x-1/2 w-5 h-5 <?php echo $dotColor; ?> rounded-full border-4 border-white shadow-lg z-10 items-center justify-center">
                    <div class="w-2 h-2 bg-white rounded-full animate-ping"></div>
                </div>

                <!-- 内容卡片 - 桌面端左右交替 -->
                <div class="w-full md:w-5/12 <?php echo $isLeft ? 'md:mr-auto md:pr-8' : 'md:ml-auto md:pl-8'; ?>">
                    <div class="bg-white rounded-2xl shadow-xl overflow-hidden transition-all duration-300 hover:shadow-2xl hover:-translate-y-1 group">

                        <!-- 顶部彩色条 -->
                        <div class="h-2 bg-gradient-to-r <?php echo $colorClass; ?>"></div>

                        <!-- 图片区域 -->
                        <?php if ($event['image']): ?>
                        <div class="relative h-48 overflow-hidden">
                            <img loading="lazy" src="<?php echo e($event['image']); ?>" alt="<?php echo e($event['title']); ?>"
                                 class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
                            <!-- 日期徽章 -->
                            <div class="absolute bottom-4 left-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white/90 text-gray-800 shadow">
                                    <?php
                                    echo $event['year'];
                                    if ($event['month'] > 0) {
                                        echo '.' . str_pad((string)$event['month'], 2, '0', STR_PAD_LEFT);
                                    }
                                    if ($event['day'] > 0) {
                                        echo '.' . str_pad((string)$event['day'], 2, '0', STR_PAD_LEFT);
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 内容区域 -->
                        <div class="p-6">
                            <!-- 日期（无图片时显示） -->
                            <?php if (!$event['image']): ?>
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gradient-to-r <?php echo $colorClass; ?> text-white">
                                    <?php
                                    echo $event['year'];
                                    if ($event['month'] > 0) {
                                        echo '.' . str_pad((string)$event['month'], 2, '0', STR_PAD_LEFT);
                                    }
                                    if ($event['day'] > 0) {
                                        echo '.' . str_pad((string)$event['day'], 2, '0', STR_PAD_LEFT);
                                    }
                                    ?>
                                </span>
                                <?php if ($event['icon']): ?>
                                <span class="text-gray-400"><?php echo getTimelineIcon($event['icon']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- 标题 -->
                            <h3 class="text-xl font-bold text-gray-800 mb-3 group-hover:text-primary transition-colors">
                                <?php if ($event['icon'] && $event['image']): ?>
                                <span class="mr-2"><?php echo getTimelineIcon($event['icon']); ?></span>
                                <?php endif; ?>
                                <?php echo e($event['title']); ?>
                            </h3>

                            <!-- 描述 -->
                            <?php if ($event['content']): ?>
                            <p class="text-gray-600 leading-relaxed">
                                <?php echo nl2br(e($event['content'])); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
                $index++;
            endforeach;
            endforeach;
            ?>

            <!-- 时间线结束标记 -->
            <div class="flex items-center justify-center mt-12" data-aos="fade-up">
                <div class="flex-1 h-px bg-gradient-to-r from-transparent to-gray-300"></div>
                <div class="mx-4 w-12 h-12 bg-gradient-to-r from-primary to-secondary rounded-full flex items-center justify-center shadow-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <div class="flex-1 h-px bg-gradient-to-l from-transparent to-gray-300"></div>
            </div>

            <p class="text-center text-gray-500 mt-6">未来可期，我们继续前行...</p>
        </div>

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
                    <?php echo count($groupedTimelines); ?>+
                </div>
                <div class="text-gray-400">发展年份</div>
            </div>
            <div data-aos="fade-up" data-aos-delay="100">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo count($timelines); ?>+
                </div>
                <div class="text-gray-400">里程碑事件</div>
            </div>
            <div data-aos="fade-up" data-aos-delay="200">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo min(array_keys($groupedTimelines)); ?>
                </div>
                <div class="text-gray-400">创立年份</div>
            </div>
            <div data-aos="fade-up" data-aos-delay="300">
                <div class="text-4xl md:text-5xl font-bold text-primary mb-2">
                    <?php echo date('Y') - min(array_keys($groupedTimelines)); ?>+
                </div>
                <div class="text-gray-400">年经验积累</div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- AOS 动画库 -->
<link href="/assets/aos/aos.css" rel="stylesheet">
<script src="/assets/aos/aos.js"></script>
<script>
AOS.init({
    duration: 800,
    once: true,
    offset: 100
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>
