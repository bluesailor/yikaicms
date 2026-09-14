<?php
declare(strict_types=1);

final class TableElement extends AbstractElement
{
    public const MAX_ROWS = 50;
    public const MAX_COLUMNS = 12;

    public function type(): string { return 'table'; }
    public function label(): string { return __('blox_el_table'); }
    public function icon(): string { return 'table'; }
    public function styles(): array { return ['/assets/css/blox-table.css']; }

    public function controls(): array
    {
        $controls = [
            ['key' => 'caption', 'type' => 'text', 'label' => __('blox_table_caption'), 'default' => ''],
            ['key' => 'grid', 'type' => 'table_grid', 'label' => __('blox_table_content'), 'default' => [
                'rows' => [[__('blox_table_parameter'), __('blox_table_value')], [__('blox_table_model'), 'YK-100'], [__('blox_table_material'), __('blox_table_material_value')]],
                'widths' => [0, 0],
            ]],
            ['key' => 'header_row', 'type' => 'checkbox', 'label' => __('blox_table_header_row'), 'default' => true],
            ['key' => 'header_column', 'type' => 'checkbox', 'label' => __('blox_table_header_column'), 'default' => false],
            ['key' => 'table_style', 'type' => 'select', 'label' => __('blox_table_style'), 'tab' => 'style', 'default' => 'lines', 'option_preview' => 'table',
                'options' => ['lines' => __('blox_table_lines'), 'striped' => __('blox_table_striped'), 'bordered' => __('blox_table_bordered'), 'brand' => __('blox_table_brand'), 'dark' => __('blox_table_dark')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_table_align'), 'tab' => 'style', 'default' => 'left',
                'options' => ['left' => __('blox_table_left'), 'center' => __('blox_table_center'), 'right' => __('blox_table_right')],
                'option_icons' => ['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right']],
            ['key' => 'density', 'type' => 'select', 'label' => __('blox_table_density'), 'tab' => 'style', 'default' => 'standard',
                'visible_when' => ['terms' => [['custom_style', '=', false]]],
                'options' => ['compact' => __('blox_table_compact'), 'standard' => __('blox_table_standard'), 'roomy' => __('blox_table_roomy')]],
            ['key' => 'border_mode', 'type' => 'select', 'label' => __('blox_table_border_mode'), 'tab' => 'style', 'default' => 'collapse',
                'options' => ['collapse' => __('blox_table_collapse'), 'separate' => __('blox_table_separate')]],
            ['key' => 'cell_spacing', 'type' => 'number', 'label' => __('blox_table_spacing'), 'tab' => 'style', 'default' => 4, 'min' => 0, 'max' => 24,
                'visible_when' => ['terms' => [['border_mode', '=', 'separate']]]],
            ['key' => 'custom_style', 'type' => 'checkbox', 'label' => __('blox_table_custom'), 'tab' => 'style', 'default' => false],
        ];
        $custom = [
            ['key' => 'header_bg', 'type' => 'color', 'label' => __('blox_table_header_bg'), 'default' => '', 'section' => __('blox_table_header_section')],
            ['key' => 'header_color', 'type' => 'color', 'label' => __('blox_table_header_color'), 'default' => '', 'section' => __('blox_table_header_section')],
            ['key' => 'header_bold', 'type' => 'checkbox', 'label' => __('blox_table_header_bold'), 'default' => true, 'section' => __('blox_table_header_section')],
            ['key' => 'header_align', 'type' => 'select', 'label' => __('blox_table_header_align'), 'default' => 'inherit', 'section' => __('blox_table_header_section'),
                'options' => ['inherit' => __('blox_table_follow'), 'left' => __('blox_table_left'), 'center' => __('blox_table_center'), 'right' => __('blox_table_right')]],
            ['key' => 'body_color', 'type' => 'color', 'label' => __('blox_table_body_color'), 'default' => '', 'section' => __('blox_table_body_section')],
            ['key' => 'body_bg', 'type' => 'color', 'label' => __('blox_table_body_bg'), 'default' => '', 'section' => __('blox_table_body_section')],
            ['key' => 'stripe_color', 'type' => 'color', 'label' => __('blox_table_stripe_color'), 'default' => '', 'section' => __('blox_table_body_section'),
                'visible_when' => ['terms' => [['custom_style', '=', true], ['table_style', '=', 'striped']]]],
            ['key' => 'border_color', 'type' => 'color', 'label' => __('blox_table_border_color'), 'default' => '', 'section' => __('blox_table_border_section')],
            ['key' => 'border_width', 'type' => 'number', 'label' => __('blox_table_border_width'), 'default' => 1, 'min' => 0, 'max' => 6, 'section' => __('blox_table_border_section')],
            ['key' => 'padding_y', 'type' => 'number', 'label' => __('blox_table_padding_y'), 'default' => 12, 'min' => 0, 'max' => 48, 'section' => __('blox_table_padding_section')],
            ['key' => 'padding_x', 'type' => 'number', 'label' => __('blox_table_padding_x'), 'default' => 16, 'min' => 0, 'max' => 48, 'section' => __('blox_table_padding_section')],
        ];
        foreach ($custom as $control) {
            $control['tab'] = 'style';
            $control['visible_when'] ??= ['terms' => [['custom_style', '=', true]]];
            $controls[] = $control;
        }
        return $controls;
    }

    /** @return array{rows:list<list<string>>,widths:list<int>} */
    public static function normalizeGrid(mixed $value): array
    {
        $source = is_array($value) && is_array($value['rows'] ?? null) ? array_slice(array_values($value['rows']), 0, self::MAX_ROWS) : [];
        $rows = [];
        $columns = 1;
        foreach ($source as $row) {
            if (!is_array($row)) continue;
            $cells = array_map(static fn (mixed $cell): string => is_scalar($cell) ? mb_substr((string) $cell, 0, 2000) : '', array_slice(array_values($row), 0, self::MAX_COLUMNS));
            $columns = max($columns, count($cells));
            $rows[] = $cells;
        }
        if ($rows === []) $rows = [['']];
        $rows = array_map(static fn (array $row): array => array_pad($row, $columns, ''), $rows);
        $rawWidths = is_array($value) && is_array($value['widths'] ?? null) ? array_values($value['widths']) : [];
        $widths = [];
        for ($i = 0; $i < $columns; $i++) {
            $width = is_scalar($rawWidths[$i] ?? null) && is_numeric($rawWidths[$i]) ? (int) $rawWidths[$i] : 0;
            $widths[] = $width > 0 ? max(80, min(800, $width)) : 0;
        }
        return ['rows' => $rows, 'widths' => $widths];
    }

    public function render(array $data, string $children = ''): string
    {
        $grid = self::normalizeGrid($data['grid'] ?? $this->defaults()['grid']);
        $style = in_array($data['table_style'] ?? null, ['lines', 'striped', 'bordered', 'brand', 'dark'], true) ? $data['table_style'] : 'lines';
        $align = in_array($data['align'] ?? null, ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $density = in_array($data['density'] ?? null, ['compact', 'standard', 'roomy'], true) ? $data['density'] : 'standard';
        $borderMode = ($data['border_mode'] ?? '') === 'separate' ? 'separate' : 'collapse';
        $spacing = is_scalar($data['cell_spacing'] ?? null) && is_numeric($data['cell_spacing']) ? max(0, min(24, (int) $data['cell_spacing'])) : 4;
        $headerRow = in_array($data['header_row'] ?? true, [true, 1, '1'], true);
        $headerColumn = in_array($data['header_column'] ?? false, [true, 1, '1'], true);
        $caption = is_scalar($data['caption'] ?? null) ? mb_substr((string) $data['caption'], 0, 2000) : '';
        $minWidth = array_sum(array_map(static fn (int $width): int => $width ?: 120, $grid['widths']));
        $css = '';
        if (in_array($data['custom_style'] ?? false, [true, 1, '1'], true)) {
            foreach (['header_bg', 'header_color', 'body_bg', 'body_color', 'stripe_color', 'border_color'] as $key) {
                $color = self::cssColor($data[$key] ?? null);
                if ($color !== null) $css .= '--table-' . str_replace('_', '-', $key) . ':' . $color . ';';
            }
            foreach (['border_width' => [1, 6], 'padding_y' => [12, 48], 'padding_x' => [16, 48]] as $key => [$fallback, $max]) {
                $value = is_scalar($data[$key] ?? null) && is_numeric($data[$key]) ? max(0, min($max, (int) $data[$key])) : $fallback;
                $css .= '--table-' . str_replace('_', '-', $key) . ':' . $value . 'px;';
            }
            $headerAlign = in_array($data['header_align'] ?? null, ['left', 'center', 'right'], true) ? $data['header_align'] : 'inherit';
            $css .= '--table-header-align:' . $headerAlign . ';--table-header-weight:' . (in_array($data['header_bold'] ?? true, [true, 1, '1'], true) ? '600' : '400') . ';';
        }
        $html = '<div class="yk-table-scroll" role="region" tabindex="0" aria-label="' . e($caption ?: $this->label()) . '">';
        $html .= '<table class="yk-table border-' . $borderMode . '" data-table-style="' . $style . '" data-density="' . $density . '" style="' . e($css) . 'min-width:' . $minWidth . 'px;text-align:' . $align . ';border-collapse:' . $borderMode . ';border-spacing:' . ($borderMode === 'separate' ? $spacing : 0) . 'px">';
        if ($caption !== '') $html .= '<caption>' . e($caption) . '</caption>';
        $html .= '<colgroup>';
        foreach ($grid['widths'] as $width) $html .= $width > 0 ? '<col style="width:' . $width . 'px">' : '<col>';
        $html .= '</colgroup>' . ($headerRow ? '<thead>' : '<tbody>');
        foreach ($grid['rows'] as $ri => $row) {
            if ($headerRow && $ri === 1) $html .= '</thead><tbody>';
            $html .= '<tr>';
            foreach ($row as $ci => $cell) {
                $scope = $headerRow && $ri === 0 ? 'col' : ($headerColumn && $ci === 0 ? 'row' : '');
                $tag = $scope !== '' ? 'th' : 'td';
                $html .= '<' . $tag . ($scope !== '' ? ' scope="' . $scope . '"' : '') . '><span data-table-text style="white-space:pre-wrap">' . e($cell) . '</span></' . $tag . '>';
            }
            $html .= '</tr>';
        }
        return $html . ($headerRow && count($grid['rows']) === 1 ? '</thead><tbody></tbody>' : '</tbody>') . '</table></div>';
    }
}
