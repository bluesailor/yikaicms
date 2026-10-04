<?php
/**
 * 字段表格（2.0.4 高级字段，免费渲染）：把扩展字段直接排成表。
 * - 选重复器：每行一条，表头是子字段名（产品参数表、型号对照表）；
 * - 选字段组：两列「名称 | 值」；
 * - 不选：当前条目的全部简单字段排成规格列表（空值跳过）。
 * 条目取当前详情页（产品 / 文章 / 自定义模型）或外层循环行；`option:键` 取全站选项。
 */

declare(strict_types=1);

final class FieldTableElement extends AbstractElement
{
    public function type(): string { return 'field-table'; }
    public function label(): string { return __('blox_el_field_table'); }
    public function icon(): string { return 'table'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }

    public function controls(): array
    {
        return [
            ['key' => 'field', 'type' => 'select', 'label' => __('blox_field_table_field'), 'default' => '', 'options' => self::fieldOptions()],
            ['key' => 'header', 'type' => 'checkbox', 'label' => __('blox_field_table_header'), 'default' => true],
            ['key' => 'style', 'type' => 'select', 'label' => __('blox_field_table_style'), 'default' => 'striped', 'tab' => 'style', 'options' => [
                'striped' => __('blox_field_table_striped'),
                'bordered' => __('blox_field_table_bordered'),
                'plain' => __('blox_field_table_plain'),
            ]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $choice = (string) ($data['field'] ?? '');
        if (str_starts_with($choice, 'option:')) {
            [$owner, $ownerId, $key] = ['site', 0, substr($choice, 7)];
        } else {
            [$owner, $ownerId] = BloxLoopQuery::fieldOwnerItem();
            $key = $choice;
            if ($owner === '' || $ownerId <= 0) {
                return '';
            }
        }
        $style = (string) ($data['style'] ?? 'striped');
        $header = !array_key_exists('header', $data) || !in_array($data['header'], [false, 0, '0', ''], true);

        if ($key === '') {
            return $this->table($style, null, $this->allFieldRows($owner, $ownerId));
        }
        $field = preg_match(ExtFields::KEY_PATTERN, $key) ? ExtFields::field($owner, $key) : null;
        if ($field === null) {
            return '';
        }
        $subs = (array) ($field['config']['sub_fields'] ?? []);
        if ($field['field_type'] === 'repeater') {
            $rows = [];
            foreach (ExtFields::rows($owner, $ownerId, $key) as $row) {
                $cells = [];
                foreach ($subs as $sub) {
                    $cells[] = ExtFields::html($sub, (string) ($row[$sub['key']] ?? ''));
                }
                $rows[] = $cells;
            }
            return $rows === [] ? '' : $this->table($style, $header ? array_map(static fn (array $s): string => (string) $s['name'], $subs) : null, $rows);
        }
        if ($field['field_type'] === 'group') {
            $values = (array) ExtFields::decode('group', ExtFields::raw($owner, $ownerId, $key));
            $rows = [];
            foreach ($subs as $sub) {
                $html = ExtFields::html($sub, is_scalar($values[$sub['key']] ?? null) ? (string) $values[$sub['key']] : '');
                if ($html !== '') {
                    $rows[] = [e((string) $sub['name']), $html];
                }
            }
            return $this->table($style, null, $rows);
        }
        $html = ExtFields::html($field, ExtFields::raw($owner, $ownerId, $key));
        return $html === '' ? '' : $this->table($style, null, [[e((string) $field['field_name']), $html]]);
    }

    /** @return list<list<string>> 名称 | 值 */
    private function allFieldRows(string $owner, int $ownerId): array
    {
        $values = getAllMeta($owner, $ownerId);
        $rows = [];
        foreach (ExtFields::fields($owner) as $field) {
            $html = ExtFields::html($field, (string) ($values[$field['field_key']] ?? ''));
            if ($html !== '') {
                $rows[] = [e((string) $field['field_name']), $html];
            }
        }
        return $rows;
    }

    /**
     * @param list<string>|null $head 已是纯文本（下面转义）
     * @param list<list<string>> $rows 单元格已是安全 HTML
     */
    private function table(string $style, ?array $head, array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $cell = $style === 'bordered' ? 'border border-gray-200 px-3 py-2' : 'px-3 py-2';
        $html = '<div class="yk-field-table overflow-x-auto"><table class="w-full text-sm text-start' . ($style === 'bordered' ? ' border-collapse' : '') . '">';
        if ($head !== null) {
            $html .= '<thead class="bg-gray-50 text-gray-600"><tr>';
            foreach ($head as $label) {
                $html .= '<th scope="col" class="' . $cell . ' text-start font-medium">' . e($label) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        $html .= '<tbody' . ($style === 'striped' ? ' class="[&>tr:nth-child(even)]:bg-gray-50"' : '') . '>';
        $twoColumn = $head === null;
        foreach ($rows as $row) {
            $html .= '<tr' . ($style === 'plain' ? ' class="border-b border-gray-100"' : '') . '>';
            foreach ($row as $i => $value) {
                $html .= $twoColumn && $i === 0
                    ? '<th scope="row" class="' . $cell . ' w-1/3 text-start font-medium text-gray-600">' . $value . '</th>'
                    : '<td class="' . $cell . ' text-gray-800">' . $value . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** 编辑器下拉：当前条目全部字段 + 各归属的重复器 / 字段组 + 全站选项里的。@return array<string,string> */
    private static function fieldOptions(): array
    {
        $options = ['' => __('blox_field_table_all')];
        try {
            foreach (ExtFields::owners() as $owner) {
                foreach (ExtFields::fields($owner) as $field) {
                    if (in_array($field['field_type'], ['relationship'], true)) {
                        continue;
                    }
                    $value = ($owner === 'site' ? 'option:' : '') . $field['field_key'];
                    $label = ExtFields::ownerLabel($owner) . ' · ' . (string) $field['field_name'];
                    $options[$value] = isset($options[$value]) ? $options[$value] . ' / ' . ExtFields::ownerLabel($owner) : $label;
                }
            }
        } catch (Throwable) {
            // 安装早期 / 缺表：只给「全部字段」
        }
        return $options;
    }
}
