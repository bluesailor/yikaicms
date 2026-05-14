<?php
/**
 * Aurora Theme - Stats Block
 * Variables: $stats（[{number, label, suffix?}]）或从 config 读
 */
$items = $stats ?? json_decode((string)config('home_stats', '[]'), true) ?: [];
if (empty($items)) {
    $items = [
        ['number' => '500', 'suffix' => '+', 'label' => __('home_stats_clients')],
        ['number' => '99.9', 'suffix' => '%', 'label' => __('home_stats_uptime')],
        ['number' => '24', 'suffix' => '/7', 'label' => __('home_stats_support')],
        ['number' => '15', 'suffix' => '+', 'label' => __('home_stats_years')],
    ];
}
?>
<section class="py-20 relative overflow-hidden">
    <!-- 渐变分隔条 -->
    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent"></div>
    <div class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-purple-500/50 to-transparent"></div>

    <!-- 背景光斑 -->
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[400px] bg-gradient-radial from-indigo-500/10 to-transparent blur-3xl pointer-events-none"></div>

    <div class="container mx-auto px-6 lg:px-8 relative">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
            <?php foreach ($items as $stat): ?>
            <div class="text-center group">
                <div class="text-4xl md:text-5xl font-bold tracking-tight">
                    <span class="bg-gradient-to-br from-indigo-300 via-purple-300 to-pink-300 bg-clip-text text-transparent">
                        <?php echo e((string)($stat['number'] ?? '0')); ?>
                    </span>
                    <?php if (!empty($stat['suffix'])): ?>
                    <span class="text-indigo-400/80 text-3xl"><?php echo e($stat['suffix']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-sm text-slate-400 tracking-wide">
                    <?php echo e($stat['label'] ?? ''); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
