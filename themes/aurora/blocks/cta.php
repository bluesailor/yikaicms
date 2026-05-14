<?php
/**
 * Aurora Theme - CTA Block
 */
$ctaTitle = config('home_cta_title', __('home_cta_title'));
$ctaDesc = config('home_cta_desc', __('home_cta_desc'));
$ctaButton = config('home_cta_button', __('home_cta_button'));
$ctaLink = config('home_cta_link', '#contact');
?>
<section class="py-24 relative overflow-hidden">
    <div class="container mx-auto px-6 lg:px-8">
        <div class="relative rounded-3xl overflow-hidden">
            <!-- 大渐变背景 -->
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-600"></div>
            <!-- Mesh overlay 模拟 -->
            <div class="absolute inset-0 opacity-30 aurora-grid"></div>
            <!-- 装饰球 -->
            <div class="absolute -top-32 -right-32 w-96 h-96 bg-pink-400/30 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-32 -left-32 w-96 h-96 bg-indigo-400/30 rounded-full blur-3xl"></div>

            <div class="relative px-8 md:px-16 py-16 md:py-20 text-center max-w-3xl mx-auto">
                <h2 class="text-3xl md:text-5xl font-bold text-white tracking-tight leading-tight">
                    <?php echo e($ctaTitle); ?>
                </h2>
                <?php if ($ctaDesc): ?>
                <p class="mt-5 text-base md:text-lg text-white/80 leading-relaxed">
                    <?php echo e($ctaDesc); ?>
                </p>
                <?php endif; ?>
                <a href="<?php echo e($ctaLink); ?>"
                   class="group inline-flex items-center gap-2 mt-10 px-8 py-3.5 rounded-xl bg-white text-slate-900 font-semibold shadow-2xl shadow-black/30 hover:shadow-black/50 hover:-translate-y-0.5 transition-all">
                    <?php echo e($ctaButton); ?>
                    <svg class="w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>
