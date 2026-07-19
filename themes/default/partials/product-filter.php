<?php
/**
 * 产品多条件筛选面板（对标 PbootCMS 多条件筛选）。
 *
 * 由 views/list/sidebar.php 在产品类型左侧栏 require。用「切换链接」实现，
 * 选中即改 URL 查询串（?brand= ?tag= ?pmin= ?pmax=），无需 JS、天然可缓存/可分享。
 * 组间 AND、组内 OR（标签按 group_name 分组）。
 *
 * 作用域变量（来自 ProductController → list.php）：
 *   $channel, $productCategory,
 *   $facetBrands, $facetTagGroups, $facetPrice,
 *   $selBrandIds, $selTagIds, $filterPriceMin, $filterPriceMax, $filterActive
 */

$facetBrands    = $facetBrands    ?? [];
$facetTagGroups = $facetTagGroups ?? [];
$facetPrice     = $facetPrice     ?? ['min' => 0, 'max' => 0];
$selBrandIds    = $selBrandIds    ?? [];
$selTagIds      = $selTagIds      ?? [];

// 无任何可筛项则不渲染
if (empty($facetBrands) && empty($facetTagGroups)) {
    return;
}

// 第 1 页基础路径（切换筛选时始终回到第 1 页）
$catSlug = $productCategory['slug'] ?? '';
$fbase = $catSlug !== ''
    ? '/product/' . $catSlug . '.html'
    : ((($channel['slug'] ?? '') !== '') ? '/' . $channel['slug'] . '.html' : '/list/' . (int) $channel['id'] . '.html');

// 当前生效的筛选参数（保留 keyword/sort，切换 facet 时不丢）
$curFilters = [];
foreach (['keyword', 'sort', 'brand', 'tag', 'pmin', 'pmax'] as $k) {
    $v = trim((string) ($_GET[$k] ?? ''));
    if ($v !== '' && $v !== 'default') {
        $curFilters[$k] = $v;
    }
}
$buildUrl = static function (array $params) use ($fbase): string {
    return $params ? $fbase . '?' . http_build_query($params) : $fbase;
};
// 在逗号列表参数里切换一个 id（选中↔取消），返回目标 URL
$toggleUrl = static function (string $param, int $id) use ($curFilters, $buildUrl): string {
    $ids = array_filter(array_map('intval', explode(',', (string) ($curFilters[$param] ?? ''))));
    $ids = in_array($id, $ids, true) ? array_diff($ids, [$id]) : array_merge($ids, [$id]);
    $p = $curFilters;
    if ($ids) {
        $p[$param] = implode(',', $ids);
    } else {
        unset($p[$param]);
    }
    return $buildUrl($p);
};
// 清空全部筛选（保留搜索词/排序）
$clearParams = array_intersect_key($curFilters, ['keyword' => 1, 'sort' => 1]);
?>
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="flex items-center justify-between bg-primary text-white px-4 py-3 font-bold">
        <span><?php echo __('filter_title'); ?></span>
        <?php if (!empty($filterActive)): ?>
        <a href="<?php echo e($buildUrl($clearParams)); ?>" class="text-xs font-normal text-white/80 hover:text-white">
            <?php echo __('filter_clear'); ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="divide-y">
        <?php // ---- 标签组（材质/颜色/规格…）---- ?>
        <?php foreach ($facetTagGroups as $groupName => $tags): ?>
        <div class="px-4 py-3">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?php echo e($groupName); ?></div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($tags as $t): $on = in_array((int) $t['id'], $selTagIds, true); ?>
                <a href="<?php echo e($toggleUrl('tag', (int) $t['id'])); ?>"
                   class="inline-flex items-center px-3 py-1 rounded-full text-sm border transition <?php echo $on ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
                    <?php echo e($t['name']); ?><span class="ml-1 opacity-60">(<?php echo (int) $t['cnt']; ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php // ---- 品牌 ---- ?>
        <?php if (!empty($facetBrands)): ?>
        <div class="px-4 py-3">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?php echo __('filter_brand'); ?></div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($facetBrands as $b): $on = in_array((int) $b['id'], $selBrandIds, true); ?>
                <a href="<?php echo e($toggleUrl('brand', (int) $b['id'])); ?>"
                   class="inline-flex items-center px-3 py-1 rounded-full text-sm border transition <?php echo $on ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
                    <?php echo e($b['name']); ?><span class="ml-1 opacity-60">(<?php echo (int) $b['cnt']; ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php // ---- 价格区间 ---- ?>
        <?php if (($facetPrice['max'] ?? 0) > 0): ?>
        <div class="px-4 py-3">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?php echo __('filter_price'); ?></div>
            <form method="get" action="<?php echo e($fbase); ?>" class="flex items-center gap-1.5">
                <?php foreach (array_diff_key($curFilters, ['pmin' => 1, 'pmax' => 1]) as $hk => $hv): ?>
                <input type="hidden" name="<?php echo e($hk); ?>" value="<?php echo e($hv); ?>">
                <?php endforeach; ?>
                <input type="number" name="pmin" value="<?php echo e($filterPriceMin ?? ''); ?>" min="0" step="any"
                       placeholder="<?php echo (int) floor($facetPrice['min']); ?>"
                       class="w-full min-w-0 border rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary">
                <span class="text-gray-400">-</span>
                <input type="number" name="pmax" value="<?php echo e($filterPriceMax ?? ''); ?>" min="0" step="any"
                       placeholder="<?php echo (int) ceil($facetPrice['max']); ?>"
                       class="w-full min-w-0 border rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary">
                <button type="submit" class="shrink-0 bg-primary text-white text-sm px-3 py-1 rounded hover:bg-secondary transition">
                    <?php echo __('filter_go'); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
