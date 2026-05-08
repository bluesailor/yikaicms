<?php
/**
 * Yikai CMS - 时间线组件（紧凑列表布局）
 *
 * 设计：左侧固定窄列日期 + 圆点连接线，右侧标题/内容/可选图，信息密度高，
 *      适合版本日志、新闻速递、会议日程。
 *
 * 入参（由 timelineBlock() 或调用方在 scope 中准备）:
 *   $groupedTimelines  array  按年份分组（key=年，value=该年事件数组）
 *   $timelineSort      string 'asc' | 'desc'
 *
 * 依赖：getTimelineIcon(), e()
 */
declare(strict_types=1);

if (empty($groupedTimelines)) return;
?>
<div class="timeline-compact max-w-4xl mx-auto">
    <?php foreach ($groupedTimelines as $year => $events): ?>
    <!-- 年份分组头 -->
    <div class="flex items-center gap-3 mb-4 mt-8 first:mt-0" data-aos="fade-up">
        <div class="px-4 py-1.5 bg-gradient-to-r from-primary to-secondary text-white font-bold rounded shadow text-sm">
            <?php echo $year; ?>
        </div>
        <div class="flex-1 h-px bg-gradient-to-r from-gray-300 to-transparent"></div>
    </div>

    <!-- 该年事件列表 -->
    <ul class="relative pl-6 border-l-2 border-gray-200 space-y-5">
        <?php foreach ($events as $event):
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
            $textColor = match($event['color']) {
                'blue'   => 'text-blue-600',
                'green'  => 'text-green-600',
                'yellow' => 'text-yellow-600',
                'red'    => 'text-red-600',
                'purple' => 'text-purple-600',
                'cyan'   => 'text-cyan-600',
                'indigo' => 'text-indigo-600',
                'pink'   => 'text-pink-600',
                'gray'   => 'text-gray-600',
                default  => 'text-primary',
            };
            $dateLabel = '';
            if ($event['month'] > 0) {
                $dateLabel .= str_pad((string)$event['month'], 2, '0', STR_PAD_LEFT);
                if ($event['day'] > 0) {
                    $dateLabel .= '.' . str_pad((string)$event['day'], 2, '0', STR_PAD_LEFT);
                }
            }
        ?>
        <li class="relative" data-aos="fade-up">
            <!-- 圆点（覆盖在主线上） -->
            <span class="absolute -left-[27px] top-1.5 w-3 h-3 <?php echo $dotColor; ?> rounded-full ring-4 ring-white shadow"></span>

            <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                <!-- 日期窄列 -->
                <div class="sm:w-20 shrink-0 text-sm font-medium <?php echo $textColor; ?>">
                    <?php echo $dateLabel !== '' ? $dateLabel : '&nbsp;'; ?>
                </div>

                <!-- 内容主体 -->
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-800 mb-1 leading-snug">
                        <?php if ($event['icon']): ?>
                        <span class="mr-1"><?php echo getTimelineIcon($event['icon']); ?></span>
                        <?php endif; ?>
                        <?php echo e($event['title']); ?>
                    </h3>
                    <?php if (!empty($event['content'])): ?>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        <?php echo nl2br(e($event['content'])); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($event['image'])): ?>
                    <img loading="lazy" src="<?php echo e($event['image']); ?>" alt="<?php echo e($event['title']); ?>"
                         class="mt-2 w-full max-w-md rounded shadow-sm">
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
</div>
