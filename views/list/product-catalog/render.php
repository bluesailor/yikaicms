<?php
/**
 * 产品目录复合元素——可组合排版模式渲染入口。
 *
 * 由 ProductCatalogElement::render() 引入，作用域内已有：
 *   $catalog  归一化排版设置（ProductCatalogLayout::settings）
 *   $context  运行上下文（与 views/list/sidebar.php 同一批键）
 *   $scripts  分类树展开脚本
 *
 * 区域标记 data-catalog-region 与结构树节点一一对应（toolbar / categories / list / pagination），
 * 编辑器据此定位画布区域；它们只是属性，不改变旧布局的盒模型。
 */

declare(strict_types=1);

extract($context, EXTR_SKIP);

$pcListUrl = channelUrl($channel);
$pcDynamicRoute = str_contains($pcListUrl, 'yk_route=');
$pcRoute = $isProductType ? 'product_list' : 'list';
$pcNav = (string) $catalog['nav'];
$pcShowNav = !empty($catalog['show_categories']) && $pcNav !== 'hidden';
$pcGap = ProductCatalogLayout::gapClass((string) $catalog['gap']);
$pcGrid = ProductCatalogLayout::gridClasses((int) $catalog['columns']);
$pcAlign = $catalog['align'] === 'center' ? 'text-center' : '';
$pcSidebarWidth = ProductCatalogLayout::sidebarWidthClass((string) $catalog['sidebar_width']);
$pcImageRatio = ProductCatalogLayout::imageRatioClass((string) $catalog['image_ratio']);
$pcUsesSidebar = $pcNav === 'sidebar' && ($pcShowNav || !empty($catalog['show_search']));
$pcNavVariant = in_array($pcNav, ['sidebar', 'top', 'toolbar'], true) ? $pcNav : 'top';
$pcToolbarVariant = $pcNav === 'sidebar' ? 'main' : 'full';
?>
<div <?php echo ProductCatalogRequest::rootAttributes($catalogQuery ?? ProductCatalogRequest::normalize($_GET)); ?> data-catalog-layout="<?php echo e((string) $catalog['mode']); ?>">
    <div class="flex flex-wrap lg:flex-nowrap <?php echo e($pcGap); ?>" data-product-catalog-layout>
        <?php if ($pcUsesSidebar): ?>
        <?php /* 侧栏：搜索 + 分类导航 + 多条件筛选 */ ?>
        <div class="w-full <?php echo e($pcSidebarWidth); ?> flex-shrink-0 space-y-4" data-product-catalog-sidebar>
            <?php if (!empty($catalog['show_search'])): ?>
            <?php $pcToolbarVariant = 'sidebar'; require __DIR__ . '/toolbar.php'; ?>
            <?php endif; ?>
            <?php if ($pcShowNav): require __DIR__ . '/nav.php'; ?>
            <?php if ($isProductType && ($__pfPath = theme_path_optional('partials/product-filter.php'))): require $__pfPath; endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="flex-1 min-w-0">
            <?php if ($pcNav === 'top' && $pcShowNav): ?>
            <div class="mb-6" data-catalog-region="categories"><?php require __DIR__ . '/nav.php'; ?></div>
            <?php endif; ?>

            <?php
            $pcToolbarVariant = $pcNav === 'sidebar' ? 'main' : 'full';
            if ($pcNav === 'toolbar' && $pcShowNav) {
                $pcToolbarVariant = 'toolbar';
            }
            require __DIR__ . '/toolbar.php';
            ?>

            <div class="<?php echo e($pcGrid . ' ' . $pcGap . ($pcAlign !== '' ? ' ' . $pcAlign : '')); ?>" data-catalog-region="list">
                <?php if (!empty($contents)): ?>
                <?php foreach ($contents as $item): ?>
                <?php require __DIR__ . '/cards.php'; ?>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="col-span-full text-center py-16 text-gray-500 bg-white rounded-lg">
                    <?php echo e((string) $catalog['empty_text']); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($catalog['show_pagination'])): ?>
            <div data-catalog-region="pagination">
                <?php require __DIR__ . '/pagination.php'; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php echo $scripts; ?>
</div>
