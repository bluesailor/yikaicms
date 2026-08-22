<?php
/**
 * 升级管理页的标签栏（upgrade.php 与 upgrade_online.php 共用）
 *
 * 此前两页各自复制一份，加标签时只改了一处，另一页就少一个入口。
 * 统一由此渲染，避免再次漂移。
 *
 * 调用前设 $__upgTab：'check' | 'online' | 'config'
 * 可选 $pendingUpgrades：非空时在「数据库升级」上显示待执行计数徽标。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

$__upgTab = $__upgTab ?? 'check';
$__upgOn  = 'px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary';
$__upgOff = 'px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300';
// 待执行迁移数：调用页已算好则直接用，否则自行探测（upgrade_online.php 不算这个）
if (isset($pendingUpgrades)) {
    $__upgPending = (int) count((array) $pendingUpgrades);
} else {
    $__upgPending = function_exists('pendingMigrationsCount') ? pendingMigrationsCount() : 0;
}
// 「数据库升级」平时不显示：它是「文件已升级、库未跟上」的恢复入口，
// 有待执行项时才冒出来（带红色计数），或用户已经停在该标签上。
$__showDb = $__upgPending > 0 || $__upgTab === 'check';
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <?php if ($__showDb): ?>
        <?php // 必须显式带 tab=check：默认标签已改为按上下文决定，不带参数会落到配置页 ?>
        <a href="/admin/upgrade.php?tab=check" class="whitespace-nowrap <?php echo $__upgTab === 'check' ? $__upgOn : $__upgOff; ?>">
            <?php echo __('upgrade_tab_db'); ?>
            <?php if ($__upgPending > 0): ?>
            <span class="ml-1.5 inline-block w-5 h-5 leading-5 text-center rounded-full bg-red-500 text-white text-xs"><?php echo $__upgPending; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="/admin/upgrade_online.php" class="whitespace-nowrap <?php echo $__upgTab === 'online' ? $__upgOn : $__upgOff; ?>"><?php echo __('upgrade_online'); ?></a>
        <a href="/admin/upgrade.php?tab=manual" class="whitespace-nowrap <?php echo $__upgTab === 'manual' ? $__upgOn : $__upgOff; ?>"><?php echo __('upgrade_tab_manual'); ?></a>
        <a href="/admin/upgrade.php?tab=history" class="whitespace-nowrap <?php echo $__upgTab === 'history' ? $__upgOn : $__upgOff; ?>"><?php echo __('upgrade_tab_history'); ?></a>
        <a href="/admin/upgrade.php?tab=config" class="whitespace-nowrap <?php echo $__upgTab === 'config' ? $__upgOn : $__upgOff; ?>"><?php echo __('upgrade_config_title'); ?></a>
    </div>
</div>
