<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
/**
 * 后台页面顶部的面包屑：上一级是链接，当前页是纯文字。
 * 用法：$breadcrumb = [[__('setup_title'), '/admin/site_setup.php'], [__('st_title')]]; require …/breadcrumb.php;
 *
 * @var list<array{0:string,1?:string}> $breadcrumb
 */
$__crumbs = isset($breadcrumb) && is_array($breadcrumb) ? $breadcrumb : [];
?>
<nav aria-label="<?= e(__('admin_breadcrumb')) ?>" class="mb-1 text-sm text-gray-500" data-testid="admin-breadcrumb">
    <ol class="flex flex-wrap items-center gap-1">
        <?php foreach ($__crumbs as $__index => $__crumb): $__last = $__index === count($__crumbs) - 1; ?>
        <li class="flex items-center gap-1">
            <?php if ($__index > 0): ?><i class="ti ti-chevron-right text-xs text-gray-400" aria-hidden="true"></i><?php endif; ?>
            <?php if (!$__last && isset($__crumb[1])): ?>
            <a href="<?= e($__crumb[1]) ?>" class="hover:text-primary hover:underline"><?= e($__crumb[0]) ?></a>
            <?php else: ?>
            <span class="text-gray-700" <?= $__last ? 'aria-current="page"' : '' ?>><?= e($__crumb[0]) ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>
