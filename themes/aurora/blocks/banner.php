<?php
/**
 * Aurora Theme - Hero Banner Block
 * Variables: $banners (each: image, title, subtitle, link_url, link_target)
 *
 * 设计：深色 hero + 大字号 gradient title + glassmorphism CTA + 背景 banner 图作为氛围 (低透明度)
 */
?>
<section class="relative min-h-[560px] md:min-h-[640px] flex items-center overflow-hidden">
    <!-- Background banner image (faded) -->
    <?php if (!empty($banners) && !empty($banners[0]['image'])): ?>
    <div class="absolute inset-0">
        <img src="<?php echo e($banners[0]['image']); ?>" alt="" class="w-full h-full object-cover opacity-25" loading="lazy">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-950/40 via-slate-950/70 to-slate-950"></div>
    </div>
    <?php endif; ?>

    <!-- Subtle animated gradient blobs -->
    <div class="absolute top-20 -left-32 w-[500px] h-[500px] bg-indigo-500/20 rounded-full blur-3xl aurora-pulse"></div>
    <div class="absolute bottom-10 -right-32 w-[500px] h-[500px] bg-purple-500/20 rounded-full blur-3xl aurora-pulse" style="animation-delay: 2s"></div>

    <div class="container mx-auto px-6 lg:px-8 relative z-10 py-20">
        <div class="max-w-3xl"<?php echo !empty($banners[0]['_blox_path']) ? ' data-yk-el="' . e($banners[0]['_blox_path']) . '" data-yk-el-type="home-banner-item"' : ''; ?>>
            <?php if (!empty($banners)): ?>
                <?php $heroBanner = $banners[0]; ?>
                <?php if (!empty($heroBanner['subtitle'])): ?>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 mb-6">
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
                    <?php echo e($heroBanner['subtitle']); ?>
                </div>
                <?php endif; ?>
                <h1 class="text-4xl md:text-6xl font-bold tracking-tight text-white leading-[1.1] mb-6">
                    <?php
                    // 把标题拆 2 段做渐变高亮：第一段白色，最后一句渐变
                    $title = $heroBanner['title'] ?? '';
                    if (preg_match('/^(.*?)([^\s，。！？,.!?]+[，。！？,.!?]?)$/u', $title, $m) && mb_strlen($title) > 4) {
                        echo e(rtrim($m[1])) . '<br><span class="bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">' . e($m[2]) . '</span>';
                    } else {
                        echo e($title);
                    }
                    ?>
                </h1>
                <?php if (!empty($heroBanner['description'])): ?>
                <p class="text-lg text-slate-400 leading-relaxed mb-10 max-w-2xl">
                    <?php echo e($heroBanner['description']); ?>
                </p>
                <?php endif; ?>
                <div class="flex flex-wrap items-center gap-4">
                    <?php if (!empty($heroBanner['link_url'])): ?>
                    <a href="<?php echo e($heroBanner['link_url']); ?>" target="<?php echo e($heroBanner['link_target'] ?? '_self'); ?>"
                       class="group inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 text-white font-medium shadow-lg shadow-indigo-500/30 hover:shadow-indigo-500/50 hover:-translate-y-0.5 transition-all">
                        <?php echo e($heroBanner['button_text'] ?? '立即开始'); ?>
                        <svg class="w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (count($banners) > 1 && !empty($banners[1]['link_url'])): ?>
                    <a href="<?php echo e($banners[1]['link_url']); ?>" target="<?php echo e($banners[1]['link_target'] ?? '_self'); ?>"
                       class="inline-flex items-center gap-2 px-6 py-3 rounded-xl border border-slate-700 text-slate-300 hover:text-white hover:border-slate-500 hover:bg-white/5 transition">
                        <?php echo e($banners[1]['button_text'] ?? $banners[1]['title'] ?? '了解更多'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <h1 class="text-4xl md:text-6xl font-bold text-white leading-tight">
                    <?php echo e(configRawLang('site_name', 'Yikai CMS')); ?>
                </h1>
                <p class="text-lg text-slate-400 mt-6">
                    <?php echo e(config('site_description', '')); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bottom fade to body -->
    <div class="absolute bottom-0 inset-x-0 h-32 bg-gradient-to-t from-slate-950 to-transparent pointer-events-none"></div>
</section>
