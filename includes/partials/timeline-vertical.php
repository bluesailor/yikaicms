<?php
/**
 * Yikai CMS - 时间线组件（垂直双边布局）
 *
 * 入参（由调用方在 scope 中准备好）:
 *   $groupedTimelines  array  按年份分组（key=年份，value=该年事件数组）
 *   $timelineSort      string 'asc' | 'desc'（仅用于结尾文案/统计参考）
 *
 * 依赖辅助函数（应在外层已加载）:
 *   getTimelineIcon(string $icon): string
 *   e(string): string
 */
declare(strict_types=1);

if (empty($groupedTimelines)) return;
?>
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
    ?>
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
                <div class="h-2 bg-gradient-to-r <?php echo $colorClass; ?>"></div>

                <?php if ($event['image']): ?>
                <div class="relative h-48 overflow-hidden">
                    <img loading="lazy" src="<?php echo e($event['image']); ?>" alt="<?php echo e($event['title']); ?>"
                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
                    <div class="absolute bottom-4 left-4">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white/90 text-gray-800 shadow">
                            <?php
                            echo $event['year'];
                            if ($event['month'] > 0) echo '.' . str_pad((string)$event['month'], 2, '0', STR_PAD_LEFT);
                            if ($event['day']   > 0) echo '.' . str_pad((string)$event['day'],   2, '0', STR_PAD_LEFT);
                            ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="p-6">
                    <?php if (!$event['image']): ?>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gradient-to-r <?php echo $colorClass; ?> text-white">
                            <?php
                            echo $event['year'];
                            if ($event['month'] > 0) echo '.' . str_pad((string)$event['month'], 2, '0', STR_PAD_LEFT);
                            if ($event['day']   > 0) echo '.' . str_pad((string)$event['day'],   2, '0', STR_PAD_LEFT);
                            ?>
                        </span>
                        <?php if ($event['icon']): ?>
                        <span class="text-gray-400"><?php echo getTimelineIcon($event['icon']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <h3 class="text-xl font-bold text-gray-800 mb-3 group-hover:text-primary transition-colors">
                        <?php if ($event['icon'] && $event['image']): ?>
                        <span class="mr-2"><?php echo getTimelineIcon($event['icon']); ?></span>
                        <?php endif; ?>
                        <?php echo e($event['title']); ?>
                    </h3>

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

    <p class="text-center text-gray-500 mt-6"><?php echo __('timeline_outro'); ?></p>
</div>
