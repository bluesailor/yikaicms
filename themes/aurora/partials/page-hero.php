<?php
/**
 * Aurora Theme - Page Hero (inner page header)
 *
 * 列表/详情页顶部 hero。
 * @var array $channel - 当前栏目
 * @var string $pageTitle - 已在 layout 算好的标题
 */
$_heroTitle = $pageTitle ?? ($channel['name'] ?? '');
$_heroDesc = $channel['description'] ?? '';
$_heroBg = $channel['hero_image'] ?? $channel['cover'] ?? '';
?>
<section class="relative overflow-hidden border-b border-slate-800/60">
    <?php if ($_heroBg): ?>
    <div class="absolute inset-0">
        <img src="<?php echo e($_heroBg); ?>" alt="" class="w-full h-full object-cover opacity-20" loading="lazy">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-950/60 via-slate-950/80 to-slate-950"></div>
    </div>
    <?php endif; ?>

    <!-- 装饰光斑 -->
    <div class="absolute top-0 left-1/4 w-96 h-64 bg-indigo-500/15 rounded-full blur-3xl"></div>
    <div class="absolute top-0 right-1/4 w-96 h-64 bg-purple-500/15 rounded-full blur-3xl"></div>

    <div class="container mx-auto px-6 lg:px-8 relative py-20 md:py-28">
        <div class="max-w-3xl">
            <h1 class="text-4xl md:text-5xl font-bold text-white tracking-tight leading-tight">
                <?php echo e($_heroTitle); ?>
            </h1>
            <?php if ($_heroDesc): ?>
            <p class="mt-5 text-base md:text-lg text-slate-400 leading-relaxed">
                <?php echo e($_heroDesc); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</section>
