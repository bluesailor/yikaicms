<?php
/**
 * 后台列表拖动排序（2026-10-11：文章分类先做，产品 / 案例 / 下载分类共用）。
 *
 * 只排同一上级下的行：换上级仍走各页的编辑弹窗。表格是拍平的树，放下后前端按「上级 → 新顺序」重排整表，
 * 下级跟着上级走（assets/js/admin-row-sort.js）；服务端按提交顺序写 sort_order = 0、1、2…
 *
 * 用法（页面已做登录与权限检查）：
 *   POST action=sort：$ids = adminRowSortIds(fn (int $id): ?array => 本页可管理的行或 null); adminRowSortApply($model, $ids);
 *   每行 <tr<?php echo adminRowSortAttrs($id, $parentId); ?>>，名称前放 adminRowSortHandle()，排序号单元格加 data-sort-cell；
 *   <tbody id="…">，页尾 echo adminRowSortScript('tbody 的 id')。
 */

declare(strict_types=1);

/**
 * 校验提交的顺序：每个 id 都得是本页可管理的行，且同一上级、同一语言。不通过直接 error() 结束请求。
 *
 * @param callable(int): ?array<string,mixed> $find
 * @return list<int>
 */
function adminRowSortIds(callable $find): array
{
    $raw = $_POST['ids'] ?? null;
    $ids = array_values(array_unique(array_filter(
        array_map('intval', is_array($raw) ? $raw : []),
        static fn (int $id): bool => $id > 0
    )));
    if ($ids === []) {
        error(__('admin_action_failed'));
    }
    $group = null;
    foreach ($ids as $id) {
        $row = $find($id);
        if (!is_array($row)) {
            error(__('admin_action_failed'));
        }
        $key = (int) ($row['parent_id'] ?? 0) . '|' . (string) ($row['lang'] ?? '');
        $group ??= $key;
        if ($key !== $group) {
            error(__('admin_action_failed'));
        }
    }
    return $ids;
}

/** 按提交顺序写 sort_order，记后台日志并返回成功。 @param list<int> $ids */
function adminRowSortApply(object $model, array $ids, string $logModule): void
{
    foreach ($ids as $index => $id) {
        $model->updateById($id, ['sort_order' => $index]);
    }
    adminLog($logModule, 'sort', '拖动排序: ' . implode(',', $ids));
    success(['order' => $ids]);
}

function adminRowSortAttrs(int $id, int $parentId): string
{
    return ' data-sort-row data-id="' . $id . '" data-parent="' . $parentId . '"';
}

function adminRowSortHandle(): string
{
    $label = e(__('nav_menu_drag_handle'));
    return '<span class="yk-row-drag cursor-grab text-gray-300 hover:text-gray-500 mr-1 align-middle" role="button" tabindex="-1"'
        . ' title="' . $label . '" aria-label="' . $label . '" data-testid="row-drag"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>';
}

function adminRowSortScript(string $tbodyId): string
{
    $options = json_encode([
        'saved' => __('admin_sort_saved'),
        'failed' => __('admin_action_failed'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    return '<script src="/assets/sortable/Sortable.min.js"></script>'
        . '<script src="' . e(assetVer('/assets/js/admin-row-sort.js')) . '"></script>'
        . '<script>ykRowSort(document.getElementById(' . json_encode($tbodyId) . '), ' . $options . ');</script>';
}
