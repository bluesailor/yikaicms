<?php
/**
 * Blox 职位目录（job-catalog 元素）：与 list.php 固定列表的招聘分支同一套卡片与分页。
 *
 * 由 JobCatalogElement 以 extract() 注入：$channel, $jobs, $total, $page, $perPage, $keyword,
 * $jobShowPagination。
 */
?>
<?php if (!empty($jobs)): ?>
<div class="space-y-4" data-job-catalog>
    <?php foreach ($jobs as $item): ?>
    <?php require theme_path('partials/job-card.php'); ?>
    <?php endforeach; ?>
</div>
<?php if ($jobShowPagination && $perPage > 0): ?>
<?php
$totalPages = (int) ceil($total / $perPage);
$pageUrl = function (int $p) use ($channel, $keyword): string {
    if (isDynamicUrlMode()) {
        return dynamicChannelPageUrl($channel, $p, $keyword !== '' ? ['keyword' => $keyword] : [])
            ?? dynamicUrl('list', ['id' => (int) ($channel['id'] ?? 0), 'page' => $p]);
    }
    $slug = $channel['slug'] ?? '';
    $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
    if ($p === 1) {
        $url = $slug ? "/{$slug}.html" : "/list/{$channel['id']}.html";
    } else {
        $url = $slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html";
    }
    return $url . $keywordParam;
};
require theme_path('partials/pagination.php');
?>
<?php endif; ?>
<?php else: ?>
<div class="text-center py-16 text-gray-500" data-job-catalog>
    <?php echo __('job_empty'); ?>
</div>
<?php endif; ?>
