<?php
/**
 * 搜索结果体（分类标签 + 结果列表 + 分页；关键词为空时是提示）。
 *
 * search.php 原生页与 Blox search 模板的 search-results 元素共用同一份标记
 * （v1.26 Site Builder：单一来源，模板路径不复制原生 140 行）。
 *
 * 期望变量（调用方作用域提供，$sr 前缀防冲突）：
 *   $srKeyword string  当前搜索词（'' = 未搜索）
 *   $srType string     当前分类键（all/article/product/case/download）
 *   $srResults array   当前页结果行
 *   $srTotal int       当前分类总数
 *   $srPage int        当前页码（1 起）
 *   $srPerPage int     每页条数
 *   $srTypeLabels array<string,string> 分类键 → 显示名
 *   $srTypeCounts array<string,int>    分类键 → 计数
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
?>
<?php if ($srKeyword === ''): ?>
<div class="text-center py-16 text-gray-400">
    <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 21-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    <p class="text-lg"><?php echo __('search_empty_hint'); ?></p>
</div>

<?php else: ?>

<!-- 分类标签 -->
<div class="flex flex-wrap gap-2 mb-6 border-b pb-4">
    <?php foreach ($srTypeLabels as $tk => $tl):
        $cnt = $srTypeCounts[$tk] ?? 0;
        $isCurrentType = ($tk === $srType);
        $tabUrl = searchUrl($srKeyword, $tk);
    ?>
    <a href="<?php echo e($tabUrl); ?>"
       class="px-4 py-2 rounded-full text-sm border transition <?php echo $isCurrentType
           ? 'bg-primary text-white border-primary'
           : 'bg-white text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
        <?php echo $tl; ?>
        <?php if ($cnt > 0): ?>
        <span class="ml-1 <?php echo $isCurrentType ? 'text-white/70' : 'text-gray-400'; ?>">(<?php echo $cnt; ?>)</span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($srTotal === 0): ?>
<div class="text-center py-12">
    <p class="text-lg text-gray-500 mb-2"><?php echo __('search_no_result'); ?> — "<span class="text-primary font-medium"><?php echo e($srKeyword); ?></span>"</p>
    <p class="text-sm text-gray-400"><?php echo __('search_try_other'); ?></p>
</div>

<?php else: ?>
<div class="mb-4 text-sm text-gray-500">
    <?php echo __('search_total', ['count' => '<span class="text-primary font-medium">' . $srTotal . '</span>']); ?>
</div>

<div class="space-y-4 max-w-3xl">
    <?php foreach ($srResults as $item):
        $title = $item['title'] ?? '';
        $summary = $item['summary'] ?: '';
        $itemType = $item['_type'] ?? $item['type'] ?? 'article';
        $channelName = $item['channel_name'] ?? '';

        // 生成 URL
        if ($itemType === 'product') {
            $url = productUrl($item);
            $typeTag = __('search_product');
            $tagColor = '#059669';
        } elseif ($itemType === 'download') {
            $url = '/download.php?id=' . $item['id'];
            $typeTag = __('search_download');
            $tagColor = '#7c3aed';
        } elseif ($itemType === 'case') {
            $url = contentUrl($item);
            $typeTag = __('search_case');
            $tagColor = '#d97706';
        } else {
            $url = contentUrl($item);
            $typeTag = __('search_article');
            $tagColor = '#2563eb';
        }

        // 高亮关键词
        $hlTitle = str_ireplace($srKeyword, '<mark class="bg-yellow-200 text-inherit px-0.5 rounded">' . e($srKeyword) . '</mark>', e($title));
        $hlSummary = $summary ? str_ireplace($srKeyword, '<mark class="bg-yellow-200 text-inherit px-0.5 rounded">' . e($srKeyword) . '</mark>', e(cutStr($summary, 200))) : '';
        $date = date('Y-m-d', (int)($item['publish_time'] ?? $item['sort_time'] ?? $item['created_at'] ?? time()));
    ?>
    <a href="<?php echo e($url); ?>" class="flex gap-4 bg-white rounded-lg shadow-sm border p-5 hover:shadow transition group">
        <?php if (!empty($item['cover'])): ?>
        <div class="flex-shrink-0 w-24 h-24 rounded overflow-hidden bg-gray-100">
            <img src="<?php echo e($item['cover']); ?>" alt="<?php echo e($title); ?>" class="w-full h-full object-cover">
        </div>
        <?php endif; ?>
        <div class="flex-1 min-w-0">
            <h3 class="text-base font-bold text-gray-800 group-hover:text-primary transition mb-1 line-clamp-1">
                <?php echo $hlTitle; ?>
            </h3>
            <?php if ($hlSummary): ?>
            <p class="text-sm text-gray-500 leading-relaxed line-clamp-2 mb-2"><?php echo $hlSummary; ?></p>
            <?php endif; ?>
            <div class="flex items-center gap-2 text-xs text-gray-400">
                <span class="px-2 py-0.5 rounded text-white" style="background:<?php echo $tagColor; ?>;"><?php echo $typeTag; ?></span>
                <?php if ($channelName): ?>
                <span><?php echo e($channelName); ?></span>
                <?php endif; ?>
                <span><?php echo $date; ?></span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- 分页 -->
<?php
$totalPages = (int)ceil($srTotal / $srPerPage);
if ($totalPages > 1):
?>
<div class="flex justify-center mt-8 gap-2">
    <?php if ($srPage > 1): ?>
    <a href="<?php echo e(searchUrl($srKeyword, $srType, $srPage - 1)); ?>" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50"><?php echo __('list_prev_page'); ?></a>
    <?php endif; ?>
    <?php for ($i = max(1, $srPage - 3); $i <= min($totalPages, $srPage + 3); $i++): ?>
    <a href="<?php echo e(searchUrl($srKeyword, $srType, $i)); ?>"
       class="px-4 py-2 border rounded-lg text-sm <?php echo $i === $srPage ? 'bg-primary text-white border-primary' : 'hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($srPage < $totalPages): ?>
    <a href="<?php echo e(searchUrl($srKeyword, $srType, $srPage + 1)); ?>" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50"><?php echo __('list_next_page'); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>
