<?php
/**
 * Yikai CMS - 时间线组件（横向滑块布局，Swiper）
 *
 * 入参（由调用方在 scope 中准备好）:
 *   $timelines         array  扁平事件数组（已按 sort_order/year/month 排好）
 *   $timelineSort      string 'asc' | 'desc'
 *
 * 依赖：
 *   /assets/swiper/swiper-bundle.min.css
 *   /assets/swiper/swiper-bundle.min.js
 *   getTimelineIcon(), e()
 */
declare(strict_types=1);

if (empty($timelines)) return;

// 依据排序方向决定显示顺序
$ordered = $timelines;
if (($timelineSort ?? 'desc') === 'asc') {
    usort($ordered, fn($a, $b) => [$a['year'], $a['month'], $a['day']] <=> [$b['year'], $b['month'], $b['day']]);
} else {
    usort($ordered, fn($a, $b) => [$b['year'], $b['month'], $b['day']] <=> [$a['year'], $a['month'], $a['day']]);
}
?>
<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">

<div class="timeline-horizontal max-w-7xl mx-auto px-4">
    <div class="relative">
        <!-- 顶部时间轴主线 -->
        <div class="hidden md:block absolute left-0 right-0 top-12 h-1 bg-gradient-to-r from-gray-200 via-gray-300 to-gray-200 rounded-full"></div>

        <div class="swiper timeline-swiper">
            <div class="swiper-wrapper pb-12">
                <?php foreach ($ordered as $i => $event):
                    $colorClass = match($event['color']) {
                        'blue'   => 'from-blue-500 to-blue-600',
                        'green'  => 'from-green-500 to-green-600',
                        'yellow' => 'from-yellow-500 to-yellow-600',
                        'red'    => 'from-red-500 to-red-600',
                        'purple' => 'from-purple-500 to-purple-600',
                        'cyan'   => 'from-cyan-500 to-cyan-600',
                        'indigo' => 'from-indigo-500 to-indigo-600',
                        'pink'   => 'from-pink-500 to-pink-600',
                        'gray'   => 'from-gray-500 to-gray-600',
                        default  => 'from-primary to-secondary',
                    };
                    $dotColor = match($event['color']) {
                        'blue'   => 'bg-blue-500',
                        'green'  => 'bg-green-500',
                        'yellow' => 'bg-yellow-500',
                        'red'    => 'bg-red-500',
                        'purple' => 'bg-purple-500',
                        'cyan'   => 'bg-cyan-500',
                        'indigo' => 'bg-indigo-500',
                        'pink'   => 'bg-pink-500',
                        'gray'   => 'bg-gray-500',
                        default  => 'bg-primary',
                    };
                    $dateStr = (string)$event['year'];
                    if ($event['month'] > 0) $dateStr .= '.' . str_pad((string)$event['month'], 2, '0', STR_PAD_LEFT);
                    if ($event['day']   > 0) $dateStr .= '.' . str_pad((string)$event['day'],   2, '0', STR_PAD_LEFT);
                ?>
                <div class="swiper-slide h-auto">
                    <div class="flex flex-col items-center pt-2">
                        <!-- 顶部日期徽章 -->
                        <div class="px-4 py-1 bg-gradient-to-r <?php echo $colorClass; ?> text-white font-bold rounded-full shadow text-sm whitespace-nowrap">
                            <?php echo $dateStr; ?>
                        </div>

                        <!-- 主线上的连接点 -->
                        <div class="hidden md:flex relative my-3 w-5 h-5 <?php echo $dotColor; ?> rounded-full border-4 border-white shadow-lg items-center justify-center">
                            <div class="w-2 h-2 bg-white rounded-full animate-ping"></div>
                        </div>
                        <div class="md:hidden h-3"></div>

                        <!-- 卡片 -->
                        <div class="w-full bg-white rounded-2xl shadow-xl overflow-hidden transition-all duration-300 hover:shadow-2xl hover:-translate-y-1 group">
                            <div class="h-1.5 bg-gradient-to-r <?php echo $colorClass; ?>"></div>

                            <?php if ($event['image']): ?>
                            <div class="relative h-40 overflow-hidden">
                                <img loading="lazy" src="<?php echo e($event['image']); ?>" alt="<?php echo e($event['title']); ?>"
                                     class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>
                            </div>
                            <?php endif; ?>

                            <div class="p-5">
                                <h3 class="text-lg font-bold text-gray-800 mb-2 group-hover:text-primary transition-colors line-clamp-2">
                                    <?php if ($event['icon']): ?>
                                    <span class="mr-1"><?php echo getTimelineIcon($event['icon']); ?></span>
                                    <?php endif; ?>
                                    <?php echo e($event['title']); ?>
                                </h3>
                                <?php if ($event['content']): ?>
                                <p class="text-gray-600 text-sm leading-relaxed line-clamp-4">
                                    <?php echo nl2br(e($event['content'])); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 翻页箭头 -->
            <div class="swiper-button-prev !text-primary !w-10 !h-10 !top-12 !-translate-y-1/2 after:!text-base after:!font-bold"></div>
            <div class="swiper-button-next !text-primary !w-10 !h-10 !top-12 !-translate-y-1/2 after:!text-base after:!font-bold"></div>

            <!-- 分页指示器 -->
            <div class="swiper-pagination !relative !mt-4"></div>
        </div>
    </div>
</div>

<script src="/assets/swiper/swiper-bundle.min.js"></script>
<script>
(function () {
    if (typeof Swiper === 'undefined') return;
    new Swiper('.timeline-swiper', {
        slidesPerView: 1,
        spaceBetween: 24,
        loop: false,
        watchOverflow: true,
        pagination: { el: '.timeline-swiper .swiper-pagination', clickable: true },
        navigation: {
            nextEl: '.timeline-swiper .swiper-button-next',
            prevEl: '.timeline-swiper .swiper-button-prev',
        },
        breakpoints: {
            640:  { slidesPerView: 2, spaceBetween: 24 },
            1024: { slidesPerView: 3, spaceBetween: 28 },
            1280: { slidesPerView: 4, spaceBetween: 32 },
        },
    });
})();
</script>
