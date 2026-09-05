<?php
/**
 * 主题化 404 正文 —— 由 render404() 套用当前主题 header/footer 后渲染。
 * 主题可放置 themes/<主题>/partials/404.php 覆盖本文件。
 * 期望变量：$notFoundMessage（提示文案，来自各控制器）。
 */
if (!defined('ROOT_PATH')) { exit('Access Denied'); }
$__msg = isset($notFoundMessage) && $notFoundMessage !== '' ? (string) $notFoundMessage : __('error_page_not_found');
?>
<section class="py-20 md:py-28">
    <div class="max-w-2xl mx-auto px-4 text-center">
        <p class="text-8xl md:text-9xl font-extrabold leading-none text-primary/20 select-none">404</p>
        <h1 class="text-2xl md:text-3xl font-bold text-gray-800 mt-2"><?php echo e($__msg); ?></h1>
        <p class="text-gray-500 mt-3"><?php echo e(__('error_404_desc')); ?></p>

        <form action="<?php echo e(function_exists('searchUrl') ? searchUrl() : '/search.php'); ?>" method="get" class="mt-8 flex items-center gap-2 max-w-md mx-auto">
            <input type="text" name="q" placeholder="<?php echo e(__('error_404_search_placeholder')); ?>"
                   class="flex-1 border border-gray-300 rounded-lg px-4 py-3 text-sm focus:outline-none focus:border-primary">
            <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-3 rounded-lg text-sm transition whitespace-nowrap">
                <?php echo e(__('search')); ?>
            </button>
        </form>

        <div class="mt-6">
            <a href="/" class="inline-flex items-center gap-2 bg-primary hover:bg-primary/90 text-white px-6 py-3 rounded-lg text-sm font-medium transition">
                <?php echo e(__('error_404_home')); ?>
            </a>
        </div>
    </div>
</section>
