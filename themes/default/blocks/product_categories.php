<?php
/**
 * 首页区块：产品分类树
 *
 * 侧栏式的分类导航（一级 + 一层子级），适合分类多的工业/批发类站点
 * （老 ShopEx 站的「首页左侧分类栏」形态）。数据源自动适配：
 *   - 常规站：product_categories 表
 *   - 「子栏目即分类」站（如 ShopEx 迁移）：product 型栏目树
 *
 * 变量：$block（版块配置：title / cols / show_search / bg_*）
 */

$bg = getBlockBg($block ?? [], '@auto');
$pcTitle   = trim((string) ($block['title'] ?? '')) ?: __('home_pc_title');
$pcCols    = max(1, min(4, (int) ($block['cols'] ?? 3)));
$pcSearch  = !empty($block['show_search']);
$pcLimit   = max(1, min(60, (int) ($block['limit'] ?? 30)));
$pcTree    = homeProductCategoryTree($pcLimit);

if (!$pcTree) {
    return;
}
$pcColClass = [1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-2 lg:grid-cols-3', 4 => 'sm:grid-cols-2 lg:grid-cols-4'][$pcCols];
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="text-center mb-10" data-animate="fade-up">
            <h2 class="text-3xl font-bold text-dark mb-2"><?php echo e($pcTitle); ?></h2>
            <?php echo homeTitleDeco(); ?>
        </div>

        <?php if ($pcSearch): ?>
        <form action="/product.html" method="get" class="max-w-md mx-auto mb-8">
            <div class="relative">
                <input type="text" name="keyword" placeholder="<?php echo e(__('home_pc_search_ph')); ?>"
                       class="w-full border border-gray-200 rounded-lg pl-4 pr-10 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
                <button type="submit" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary" aria-label="<?php echo e(__('home_pc_search_ph')); ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </button>
            </div>
        </form>
        <?php endif; ?>

        <div class="grid grid-cols-1 <?php echo $pcColClass; ?> gap-5" data-animate="fade-up">
            <?php foreach ($pcTree as $pcCat): ?>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition overflow-hidden">
                <a href="<?php echo e($pcCat['url']); ?>" class="flex items-center gap-2 px-5 py-3.5 bg-gray-50 hover:bg-primary/5 transition group">
                    <span class="w-1.5 h-4 bg-primary rounded-sm flex-shrink-0"></span>
                    <span class="font-bold text-gray-800 group-hover:text-primary transition flex-1 min-w-0 truncate"><?php echo e($pcCat['name']); ?></span>
                    <svg class="w-4 h-4 text-gray-300 group-hover:text-primary transition flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>
                <?php if (!empty($pcCat['children'])): ?>
                <div class="px-5 py-3 flex flex-wrap gap-x-3 gap-y-2 text-sm">
                    <?php foreach ($pcCat['children'] as $pcSub): ?>
                    <a href="<?php echo e($pcSub['url']); ?>" class="text-gray-500 hover:text-primary transition"><?php echo e($pcSub['name']); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
