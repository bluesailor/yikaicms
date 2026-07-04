<?php
/**
 * 父栏目内容编辑提醒横幅。
 *
 * 当被编辑的栏目下含子页面时显示：说明它是父栏目（前台为下拉菜单）、
 * 列出子页快捷入口、并提示「不需要下拉就删子页变单页」。
 * 取代旧的「父栏目静默/硬跳转到第一个子页」行为——父栏目本身可直接编辑。
 *
 * 需在包含前准备：
 *   $page      当前栏目行（含 name）
 *   $children  子栏目数组（channelModel()->getByParent($id, true) 的结果）
 *   $id        当前栏目 id
 *   $childEditBase  子页编辑器基址，默认 /admin/page_edit.php（高级页传 page_edit_advance.php）
 */
if (!defined('ROOT_PATH')) { exit('Access Denied'); }
if (empty($children)) { return; }
$childEditBase = $childEditBase ?? '/admin/page_edit.php';
?>
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <i class="ti ti-info-circle text-lg text-blue-500 mt-0.5 shrink-0"></i>
    <div class="text-sm text-blue-800 flex-1">
        <p class="font-medium"><?php echo sprintf(__('pe_parent_notice_body'), e($page['name']), count($children)); ?></p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="text-blue-600 text-xs"><?php echo __('pe_parent_children_label'); ?></span>
            <?php foreach ($children as $c):
                $isPage = ($c['type'] ?? '') === 'page';
                $href = $isPage
                    ? $childEditBase . '?id=' . (int) $c['id']
                    : '/admin/channel.php?edit=' . (int) $c['id'] . '&tab=main';
            ?>
            <a href="<?php echo e($href); ?>" class="inline-flex items-center gap-1 bg-white border border-blue-200 rounded px-2.5 py-1 text-blue-700 hover:border-blue-400 hover:text-blue-900 transition">
                <i class="ti ti-file-text text-xs"></i><?php echo e($c['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="mt-2 text-blue-600 text-xs"><?php echo sprintf(__('pe_parent_single_hint'), '<a href="/admin/channel.php?edit=' . (int) $id . '&tab=main" class="underline hover:text-blue-800">' . __('pe_channel_management') . '</a>'); ?></p>
    </div>
</div>
