<?php
/**
 * 后台列表页共享 UI 组件（借鉴 WordPress 列表范式）
 *
 * 三件套，供 文章/产品/案例/单页/下载/招聘 等列表复用，避免各页复制标记：
 *   renderRowActions()  标题下的行内操作（编辑 | 复制 | 移至回收站 | 查看），悬停显现
 *   renderBulkBar()     底部「批量操作 ▾ + 应用」下拉，替代并排按钮
 *   renderCoverCell()   封面缩略图，无图时同尺寸占位保证列对齐
 *
 * 约定：调用页需自行定义 JS 的 batchAction(action) 与 deleteItem(id)；
 * 若传 duplicate 还需 duplicateItem(id)。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * 行内操作条。
 *
 * @param array{
 *   id:int, edit:string, view?:string, duplicate?:bool,
 *   delete_fn?:string, dup_fn?:string
 * } $o
 */
function renderRowActions(array $o): string
{
    $id      = (int) $o['id'];
    $edit    = (string) $o['edit'];
    $view    = (string) ($o['view'] ?? '');
    $delFn   = (string) ($o['delete_fn'] ?? 'deleteItem');
    $dupFn   = (string) ($o['dup_fn'] ?? 'duplicateItem');
    $canDup  = !empty($o['duplicate']);

    $sep   = '<span class="text-gray-300">|</span>';
    $parts = ['<a href="' . e($edit) . '" class="hover:text-primary hover:underline">' . __('admin_edit') . '</a>'];

    if ($canDup) {
        $parts[] = '<button type="button" onclick="' . e($dupFn) . '(' . $id . ')" class="hover:text-primary hover:underline">' . __('admin_duplicate') . '</button>';
    }
    $parts[] = '<button type="button" onclick="' . e($delFn) . '(' . $id . ')" class="hover:text-primary hover:underline">' . __('admin_move_to_trash') . '</button>';
    if ($view !== '') {
        $parts[] = '<a href="' . e($view) . '" target="_blank" rel="noopener" class="hover:text-primary hover:underline">' . __('admin_view') . '</a>';
    }

    // 桌面端悬停显现、移动端常驻；始终占位避免悬停时行高跳动
    return '<div class="row-actions mt-1 flex items-center gap-2 text-sm text-gray-600 opacity-100 md:opacity-0 md:group-focus-within:opacity-100 md:group-hover:opacity-100 transition-opacity">'
        . implode($sep, $parts)
        . '</div>';
}

/**
 * 批量操作栏：下拉选动作 + 应用按钮 + 已选计数。
 * 首次调用会附带一次性 JS（applyBulk / 计数刷新）。
 *
 * @param array<string,string> $actions  动作值 => 显示名
 */
function renderBulkBar(array $actions): string
{
    static $scriptDone = false;

    $opts = '<option value="">' . __('admin_bulk_actions') . '</option>';
    foreach ($actions as $val => $label) {
        $opts .= '<option value="' . e((string) $val) . '">' . e((string) $label) . '</option>';
    }

    $html = '<div class="flex items-center gap-2">'
        . '<select id="bulkAction" class="border rounded px-3 py-1.5 text-sm bg-white">' . $opts . '</select>'
        . '<button type="button" onclick="applyBulk()" class="border px-4 py-1.5 rounded text-sm hover:bg-gray-50 text-gray-700">' . __('admin_apply') . '</button>'
        . '<span id="bulkCount" class="text-xs text-gray-400"></span>'
        . '</div>';

    if (!$scriptDone) {
        $scriptDone = true;
        $pick = json_encode(__('admin_bulk_pick_action'), JSON_UNESCAPED_UNICODE);
        $pre  = json_encode(__('admin_selected_prefix'), JSON_UNESCAPED_UNICODE);
        $html .= <<<HTML
<script>
function applyBulk() {
    var sel = document.getElementById('bulkAction');
    if (!sel || !sel.value) { showMessage({$pick}, 'error'); return; }
    batchAction(sel.value);   // 由各列表页自行实现
}
function refreshBulkCount() {
    var n = document.querySelectorAll('input[name="ids[]"]:checked').length;
    var el = document.getElementById('bulkCount');
    if (el) { el.textContent = n ? ({$pre} + n) : ''; }
}
document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && (t.name === 'ids[]' || t.id === 'checkAll' || (t.classList && t.classList.contains('row-check')))) {
        refreshBulkCount();
    }
});
</script>
HTML;
    }

    return $html;
}

/** 封面单元格：有图出缩略图，无图出同尺寸占位（保证标题列左边缘对齐）。 */
function renderCoverCell(string $cover, string $sizeCls = 'w-10 h-10'): string
{
    if ($cover !== '') {
        $src = function_exists('thumbnail') ? thumbnail($cover, 'thumb') : $cover;
        return '<img src="' . e($src) . '" alt="" class="' . $sizeCls . ' rounded object-cover flex-shrink-0">';
    }
    return '<span class="' . $sizeCls . ' rounded bg-gray-100 text-gray-300 flex items-center justify-center flex-shrink-0" title="' . __('admin_no_cover') . '">'
        . '<i class="ti ti-photo text-lg"></i></span>';
}

/** 状态 + 日期合并单元格（状态可点切换，下方为时间）。 */
function renderStatusDateCell(int $id, int $status, int $ts, string $toggleFn = 'toggleStatus'): string
{
    $badge = $status
        ? '<span class="text-green-600">' . __('admin_published') . '</span>'
        : '<span class="text-gray-400">' . __('admin_draft') . '</span>';

    return '<button onclick="' . e($toggleFn) . '(' . $id . ')" class="status-btn-' . $id . ' text-sm block">' . $badge . '</button>'
        . '<span class="text-gray-400 text-xs">' . ($ts ? date('Y-m-d H:i', $ts) : '-') . '</span>';
}
