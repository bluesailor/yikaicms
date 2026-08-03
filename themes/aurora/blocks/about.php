<?php
/**
 * Aurora Theme - About Block
 * Variables: $aboutChannel
 */
$aboutLayout = config('home_about_layout', 'text_left');
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1551434678-e076c223a692?w=800&q=80');
$aboutTitle = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
if ($aboutTitle === '') { $aboutTitle = __('home_about_title') . configRawLang('site_name', ''); }
?>
<section class="py-24 relative">
    <div class="container mx-auto px-6 lg:px-8 relative">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
            <?php if ($aboutLayout === 'image_left'): ?>
            <div class="relative">
                <div class="absolute -inset-4 bg-gradient-to-r from-indigo-500/20 to-purple-500/20 rounded-2xl blur-2xl"></div>
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>"
                     class="relative w-full rounded-2xl border border-slate-800 shadow-2xl shadow-black/50">
            </div>
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 mb-5">
                    ABOUT
                </div>
                <h2 class="text-3xl md:text-4xl font-bold text-white tracking-tight leading-tight">
                    <?php echo e($aboutTitle); ?>
                </h2>
                <p class="text-slate-400 leading-relaxed mt-6 text-base">
                    <?php echo e(config('home_about_content', config('site_description', ''))); ?>
                </p>
                <?php if ($aboutChannel ?? null): ?>
                <a href="<?php echo e(config('home_about_link', '') ?: channelUrl($aboutChannel)); ?>"
                   class="group inline-flex items-center gap-2 mt-8 px-5 py-2.5 rounded-lg border border-slate-700 text-slate-300 text-sm hover:text-white hover:border-slate-500 hover:bg-white/5 transition">
                    <?php echo e(config('home_about_button', '') ?: __('home_learn_more')); ?>
                    <svg class="w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 mb-5">
                    ABOUT
                </div>
                <h2 class="text-3xl md:text-4xl font-bold text-white tracking-tight leading-tight">
                    <?php echo e($aboutTitle); ?>
                </h2>
                <p class="text-slate-400 leading-relaxed mt-6 text-base">
                    <?php echo e(config('home_about_content', config('site_description', ''))); ?>
                </p>
                <?php if ($aboutChannel ?? null): ?>
                <a href="<?php echo e(config('home_about_link', '') ?: channelUrl($aboutChannel)); ?>"
                   class="group inline-flex items-center gap-2 mt-8 px-5 py-2.5 rounded-lg border border-slate-700 text-slate-300 text-sm hover:text-white hover:border-slate-500 hover:bg-white/5 transition">
                    <?php echo e(config('home_about_button', '') ?: __('home_learn_more')); ?>
                    <svg class="w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
                <?php endif; ?>
            </div>
            <div class="relative">
                <div class="absolute -inset-4 bg-gradient-to-r from-indigo-500/20 to-purple-500/20 rounded-2xl blur-2xl"></div>
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>"
                     class="relative w-full rounded-2xl border border-slate-800 shadow-2xl shadow-black/50">
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
