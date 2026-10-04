<?php
/**
 * 扩展字段后台控件（2.0.4）：extfield_render.php 与栏目 / 产品分类弹窗页共用。
 * 输出全部经 e() 转义；值的校验在 ExtFields::sanitize()。
 */

if (!defined('ROOT_PATH')) exit('Access Denied');

/** 字段脚本与文案（页面里只需一次；弹窗页在页面底部直接调用）。 */
function efScriptsHtml(): string
{
    return '<script>window.YK_EF_I18N = window.YK_EF_I18N || ' . json_encode([
        'row' => __('ef_row_no'),
        'max_rows' => __('ef_max_rows'),
        'no_results' => __('ef_rel_no_results'),
        'max_related' => __('ef_rel_max'),
        'uploading' => __('ef_uploading'),
        'upload_failed' => __('admin_fail'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
        . '<script src="/assets/js/admin-extfields.js?v=' . (int) @filemtime(ROOT_PATH . '/assets/js/admin-extfields.js') . '" defer></script>';
}

/**
 * 单个输入控件（顶层字段与重复器 / 组的子字段共用）。
 * @param array<string,mixed> $def {field_type|type, options, placeholder, config}
 */
function efInputHtml(array $def, string $name, string $value, string $id, bool $required = false): string
{
    $type = (string) ($def['field_type'] ?? $def['type'] ?? 'text');
    $ph = e((string) ($def['placeholder'] ?? ''));
    $req = $required ? ' required' : '';
    $base = 'w-full border rounded px-3 py-2';
    $n = e($name);
    $v = e($value);
    switch ($type) {
        case 'textarea':
            return '<textarea name="' . $n . '" id="' . e($id) . '" rows="3" placeholder="' . $ph . '" class="' . $base . '"' . $req . '>' . $v . '</textarea>';
        case 'richtext':
            return '<textarea name="' . $n . '" id="' . e($id) . '" rows="8" class="' . $base . '" data-ef-richtext>' . $v . '</textarea>';
        case 'number':
            return '<input type="number" step="any" name="' . $n . '" id="' . e($id) . '" value="' . $v . '" placeholder="' . $ph . '" class="' . $base . '"' . $req . '>';
        case 'date':
            return '<input type="date" name="' . $n . '" id="' . e($id) . '" value="' . $v . '" class="' . $base . '"' . $req . '>';
        case 'switch':
            return '<input type="hidden" name="' . $n . '" value="0">'
                . '<label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" name="' . $n . '" id="' . e($id) . '" value="1"' . ($value === '1' ? ' checked' : '') . ' class="h-4 w-4">'
                . '<span class="text-sm text-gray-600">' . e(__('admin_enabled')) . '</span></label>';
        case 'select':
            $html = '<select name="' . $n . '" id="' . e($id) . '" class="' . $base . '"' . $req . '><option value="">' . e(__('ef_choose')) . '</option>';
            foreach (ExtFields::parseOptions((string) ($def['options'] ?? '')) as $key => $label) {
                $html .= '<option value="' . e((string) $key) . '"' . ((string) $key === $value ? ' selected' : '') . '>' . e($label) . '</option>';
            }
            return $html . '</select>';
        case 'multi_select':
            $picked = $value === '' ? [] : explode(',', $value);
            $html = '<input type="hidden" name="' . e(efPresentName($name)) . '" value="1"><div class="flex flex-wrap gap-x-4 gap-y-2">';
            foreach (ExtFields::parseOptions((string) ($def['options'] ?? '')) as $key => $label) {
                $html .= '<label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="' . $n . '[]" value="' . e((string) $key) . '"'
                    . (in_array((string) $key, $picked, true) ? ' checked' : '') . '> ' . e($label) . '</label>';
            }
            return $html . '</div>';
        case 'image':
            return '<div class="flex items-start gap-3" data-ef-image>'
                . '<input type="hidden" name="' . $n . '" value="' . $v . '" data-ef-value>'
                . '<div class="h-20 w-20 shrink-0 rounded border bg-gray-50 bg-cover bg-center" data-ef-preview style="' . ($value !== '' ? 'background-image:url(\'' . e(str_replace("'", '%27', $value)) . '\')' : '') . '"></div>'
                . '<div class="flex flex-col gap-1"><button type="button" class="text-sm text-primary hover:underline text-start" data-ef-pick>' . e(__('ef_pick_image')) . '</button>'
                . '<button type="button" class="text-sm text-gray-500 hover:text-red-600 text-start" data-ef-clear>' . e(__('admin_delete')) . '</button></div></div>';
        case 'images':
            $thumbs = '';
            foreach ($value === '' ? [] : explode(',', $value) as $url) {
                $thumbs .= efThumbHtml(trim($url));
            }
            return '<div data-ef-images><input type="hidden" name="' . $n . '" value="' . $v . '" data-ef-value>'
                . '<div class="flex flex-wrap gap-2" data-ef-thumbs>' . $thumbs . '</div>'
                . '<button type="button" class="mt-2 text-sm text-primary hover:underline" data-ef-add-image><i class="ti ti-plus"></i> ' . e(__('ef_add_image')) . '</button></div>';
        case 'file':
            return '<div class="flex gap-2" data-ef-file><input type="text" name="' . $n . '" id="' . e($id) . '" value="' . $v . '" placeholder="' . ($ph !== '' ? $ph : e(__('admin_attachment_url'))) . '" class="flex-1 border rounded px-3 py-2" data-ef-value' . $req . '>'
                . '<button type="button" class="shrink-0 rounded bg-gray-500 px-3 py-2 text-sm text-white hover:bg-gray-600" data-ef-upload><i class="ti ti-upload"></i> ' . e(__('admin_choose_file')) . '</button>'
                . '<input type="file" class="hidden" data-ef-file-input></div>';
        case 'link':
            $link = json_decode($value, true);
            $link = is_array($link) ? $link : [];
            return '<div class="grid gap-2 sm:grid-cols-[2fr_1fr_auto] items-center">'
                . '<input type="text" name="' . $n . '[url]" id="' . e($id) . '" value="' . e((string) ($link['url'] ?? '')) . '" placeholder="' . e(__('ef_link_url')) . '" class="' . $base . '"' . $req . '>'
                . '<input type="text" name="' . $n . '[title]" value="' . e((string) ($link['title'] ?? '')) . '" placeholder="' . e(__('ef_link_title')) . '" class="' . $base . '">'
                . '<label class="inline-flex items-center gap-1 text-sm text-gray-600 whitespace-nowrap"><input type="checkbox" name="' . $n . '[target]" value="_blank"' . (($link['target'] ?? '') === '_blank' ? ' checked' : '') . '> ' . e(__('ef_link_new_tab')) . '</label></div>';
        case 'color':
            $swatch = preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : '#000000';
            return '<div class="flex items-center gap-2" data-ef-color><input type="color" value="' . e($swatch) . '" class="h-9 w-12 cursor-pointer rounded border" data-ef-swatch>'
                . '<input type="text" name="' . $n . '" id="' . e($id) . '" value="' . $v . '" placeholder="#1e40af" maxlength="9" class="w-32 border rounded px-3 py-2 font-mono text-sm" data-ef-value' . $req . '></div>';
    }
    return '<input type="text" name="' . $n . '" id="' . e($id) . '" value="' . $v . '" placeholder="' . $ph . '" class="' . $base . '"' . $req . '>';
}

function efThumbHtml(string $url): string
{
    if ($url === '') {
        return '';
    }
    return '<div class="relative h-16 w-16 rounded border bg-gray-50 bg-cover bg-center" data-ef-thumb="' . e($url) . '" style="background-image:url(\'' . e(str_replace("'", '%27', $url)) . '\')">'
        . '<button type="button" class="absolute -end-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs text-white" data-ef-remove-image aria-label="' . e(__('admin_delete')) . '">&times;</button></div>';
}

/** ext_fields[a][b] → ext_fields[__present_a]（多选 / 重复器清空时也能提交）；只用于顶层字段。 */
function efPresentName(string $name): string
{
    return preg_match('/^ext_fields\[([a-z][a-z0-9_]*)\]$/', $name, $m) ? 'ext_fields[__present_' . $m[1] . ']' : '__ef_ignore';
}

/** 子字段集（字段组一套、重复器每行一套）。 */
function efSubFieldsHtml(array $subs, string $prefix, array $values, string $idBase): string
{
    $html = '<div class="grid gap-3 sm:grid-cols-2">';
    foreach ($subs as $sub) {
        $key = (string) $sub['key'];
        $wide = in_array($sub['type'], ['textarea', 'link'], true) ? ' sm:col-span-2' : '';
        $html .= '<div class="' . trim($wide) . '"><label class="mb-1 block text-xs font-medium text-gray-600">' . e((string) $sub['name']) . '</label>'
            . efInputHtml($sub, $prefix . '[' . $key . ']', is_scalar($values[$key] ?? null) ? (string) $values[$key] : '', $idBase . '_' . $key) . '</div>';
    }
    return $html . '</div>';
}

function efRepeaterRowHtml(array $subs, string $name, string $index, array $values, string $idBase): string
{
    return '<div class="rounded border bg-gray-50 p-3" data-ef-row>'
        . '<div class="mb-2 flex items-center gap-2 text-xs text-gray-500"><span class="font-medium" data-ef-row-no></span>'
        . '<span class="ms-auto flex gap-1">'
        . '<button type="button" class="rounded px-1.5 hover:bg-gray-200" data-ef-row-up aria-label="' . e(__('ef_move_up')) . '"><i class="ti ti-arrow-up"></i></button>'
        . '<button type="button" class="rounded px-1.5 hover:bg-gray-200" data-ef-row-down aria-label="' . e(__('ef_move_down')) . '"><i class="ti ti-arrow-down"></i></button>'
        . '<button type="button" class="rounded px-1.5 text-red-600 hover:bg-red-50" data-ef-row-remove aria-label="' . e(__('admin_delete')) . '"><i class="ti ti-trash"></i></button>'
        . '</span></div>'
        . efSubFieldsHtml($subs, $name . '[' . $index . ']', $values, $idBase . '_' . $index) . '</div>';
}

/** 关联字段：已选条目（按选择顺序）+ 搜索。 */
function efRelationshipHtml(array $field, string $name, string $value): string
{
    $target = (string) ($field['config']['target'] ?? 'product');
    $chips = '';
    foreach (efRelationshipTitles($target, ExtFields::decode('relationship', $value)) as $id => $title) {
        $chips .= '<span class="inline-flex items-center gap-1 rounded bg-primary/10 px-2 py-1 text-sm text-primary" data-ef-rel-item="' . (int) $id . '">'
            . e($title) . '<button type="button" class="text-gray-500 hover:text-red-600" data-ef-rel-remove aria-label="' . e(__('admin_delete')) . '">&times;</button></span>';
    }
    return '<div data-ef-relationship data-target="' . e($target) . '" data-max="' . (int) ($field['config']['max'] ?? 0) . '">'
        . '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '" data-ef-value>'
        . '<div class="mb-2 flex flex-wrap gap-2" data-ef-rel-chips>' . $chips . '</div>'
        . '<div class="relative"><input type="search" class="w-full border rounded px-3 py-2 text-sm" placeholder="' . e(__('ef_rel_search')) . '" data-ef-rel-search autocomplete="off">'
        . '<div class="absolute z-40 mt-1 hidden max-h-60 w-full overflow-y-auto rounded border bg-white shadow" data-ef-rel-results></div></div></div>';
}

/** @return array<int,string> id => 标题（保持顺序；已删除的条目略过） */
function efRelationshipTitles(string $target, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $table = $target === 'product' ? 'products' : 'contents';
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rows = db()->fetchAll('SELECT id, title FROM ' . DB_PREFIX . $table . ' WHERE id IN (' . $marks . ')', $ids);
    } catch (Throwable) {
        return [];
    }
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = (string) $row['title'];
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[$id] = $byId[$id];
        }
    }
    return $out;
}
