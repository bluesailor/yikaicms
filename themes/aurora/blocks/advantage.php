<?php
/**
 * Aurora Theme - Advantage / Features Block
 *
 * 期望: $advantages 数组（icon / title / description）
 * 不提供时从 config 兜底
 */
$items = $advantages ?? json_decode((string)config('home_advantages', '[]'), true) ?: [];

// 默认配 3 条（兜底体验）
if (empty($items)) {
    $items = [
        ['title' => __('home_advantage_1_title'), 'description' => __('home_advantage_1_desc'), 'icon' => 'bolt'],
        ['title' => __('home_advantage_2_title'), 'description' => __('home_advantage_2_desc'), 'icon' => 'shield'],
        ['title' => __('home_advantage_3_title'), 'description' => __('home_advantage_3_desc'), 'icon' => 'sparkles'],
    ];
}

// 图标库（key => SVG path）
$icons = [
    'bolt'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
    'shield'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
    'sparkles' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>',
    'chart'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
    'globe'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    'users'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>',
];
?>
<section class="py-24 relative">
    <div class="container mx-auto px-6 lg:px-8">
        <div class="text-center max-w-2xl mx-auto mb-16">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 mb-5">
                FEATURES
            </div>
            <h2 class="text-3xl md:text-4xl font-bold text-white tracking-tight">
                <?php echo e(configLang('home_advantage_title', 'home_advantage_title')); ?>
            </h2>
            <?php if ($_advDesc = config('home_advantage_desc', '')): ?>
            <p class="text-slate-400 mt-4 text-base leading-relaxed">
                <?php echo e($_advDesc); ?>
            </p>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($items as $i => $item): ?>
            <?php $iconKey = $item['icon'] ?? 'sparkles'; ?>
            <div class="group relative p-7 rounded-2xl aurora-glass aurora-glass-hover transition">
                <!-- Icon -->
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500/20 to-purple-500/20 border border-indigo-500/30 flex items-center justify-center mb-5 group-hover:shadow-lg group-hover:shadow-indigo-500/20 transition">
                    <svg class="w-6 h-6 text-indigo-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <?php echo $icons[$iconKey] ?? $icons['sparkles']; ?>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-slate-100 mb-2">
                    <?php echo e($item['title'] ?? ''); ?>
                </h3>
                <p class="text-sm text-slate-400 leading-relaxed">
                    <?php echo e($item['description'] ?? ($item['desc'] ?? '')); ?>
                </p>
                <!-- decorative corner -->
                <div class="absolute -top-px -right-px w-20 h-20 bg-gradient-to-bl from-indigo-500/10 to-transparent rounded-tr-2xl rounded-bl-full opacity-0 group-hover:opacity-100 transition"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
