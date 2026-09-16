<?php
/**
 * 产品目录——分类导航区域（结构树「分类导航」节点对应）。
 *
 * 三种位置：sidebar（纵向树，沿用经典样式）/ top（横向换行）/ toolbar（工具栏筛选条）。
 * 分类项一律走现有 productCategoryUrl()/channelUrl()，当前项带可访问的选中态。
 */

declare(strict_types=1);

extract($context, EXTR_SKIP);

$pcNavVariant = in_array($pcNavVariant ?? 'sidebar', ['sidebar', 'top', 'toolbar'], true) ? $pcNavVariant : 'sidebar';

if (!function_exists('pcCatalogCategoryTree')) {
    /**
     * 递归输出分类树节点；$horizontal=true 时输出工具栏/顶部用的横向链接。
     *
     * @param list<array<string,mixed>> $items
     */
    function pcCatalogCategoryTree(array $items, int $level, bool $isProductType, bool $horizontal): void
    {
        foreach ($items as $item) {
            $hasChildren = !empty($item['children']);
            $active = !empty($item['is_active']);
            $url = $isProductType ? productCategoryUrl($item) : channelUrl($item);
            if ($horizontal) {
                $cls = 'inline-flex items-center rounded-full border px-3 py-1 text-sm transition '
                    . ($active ? 'border-primary bg-primary text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-primary hover:text-primary');
                ?>
                <a href="<?php echo e($url); ?>" class="<?php echo $cls; ?>"><?php echo e((string) $item['name']); ?></a>
                <?php
                if ($hasChildren && $active) {
                    pcCatalogCategoryTree($item['children'], $level + 1, $isProductType, true);
                }
                continue;
            }
            $paddingLeft = 16 + ($level * 16);
            ?>
            <div class="category-item">
                <div class="flex items-center justify-between hover:bg-gray-50 transition <?php echo $active ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
                    <a href="<?php echo e($url); ?>" class="flex-1 py-3 block" style="padding-left: <?php echo (int) $paddingLeft; ?>px;">
                        <?php echo e((string) $item['name']); ?>
                    </a>
                    <?php if ($hasChildren): ?>
                    <button type="button" class="category-toggle px-4 py-3 text-gray-400 hover:text-primary" data-expanded="true">
                        <svg class="w-4 h-4 transition-transform rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($hasChildren): ?>
                <div class="category-children">
                    <?php pcCatalogCategoryTree($item['children'], $level + 1, $isProductType, false); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}

if ($pcNavVariant !== 'sidebar'): ?>
<div class="flex flex-wrap items-center gap-2" data-catalog-categories>
    <a href="<?php echo channelUrl($rootChannel); ?>"
       class="inline-flex items-center rounded-full border px-3 py-1 text-sm transition <?php echo ($productCategoryId === 0 && $keyword === '') ? 'border-primary bg-primary text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-primary hover:text-primary'; ?>">
        <?php echo __('all'); ?><?php echo $isProductType ? __('list_product') : e((string) $rootChannel['name']); ?>
    </a>
    <?php pcCatalogCategoryTree($categoryTree, 0, $isProductType, true); ?>
</div>
<?php else: ?>
<div class="bg-white rounded-lg shadow overflow-hidden sticky top-20" data-catalog-categories>
    <div class="bg-white text-gray-900 px-4 py-4 text-lg font-semibold border-b border-gray-200" data-catalog-title>
        <?php echo e((string) $rootChannel['name']); ?>
    </div>
    <div class="divide-y">
        <?php if ($isProductType): ?>
        <a href="<?php echo channelUrl($rootChannel); ?>"
           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo ($productCategoryId === 0 && $keyword === '') ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
            <?php echo __('all'); ?><?php echo __('list_product'); ?>
        </a>
        <?php if ($channel['parent_id'] > 0): ?>
        <a href="<?php echo ($channel['parent_id'] == (int) $rootChannel['id']) ? channelUrl($rootChannel) : productCategoryUrl(getChannel((int) $channel['parent_id'])); ?>"
           class="block px-4 py-2 text-sm text-gray-500 hover:text-primary hover:bg-gray-50 transition">
            ← <?php echo __('back'); ?>
        </a>
        <?php endif; ?>
        <?php pcCatalogCategoryTree($categoryTree, 0, true, false); ?>
        <?php else: ?>
        <a href="<?php echo channelUrl($rootChannel); ?>"
           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo $channelId === (int) $rootChannel['id'] ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
            <?php echo __('all'); ?><?php echo e((string) $rootChannel['name']); ?>
        </a>
        <?php pcCatalogCategoryTree($categoryTree, 0, false, false); ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
