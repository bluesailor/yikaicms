<?php
/** 分隔线元素。对齐旧 case 'divider'。 */

declare(strict_types=1);

final class DividerElement extends AbstractElement
{
    private const SPACING_MAP = ['sm' => 'my-2', 'md' => 'my-4', 'lg' => 'my-8'];

    public function type(): string { return 'divider'; }
    public function label(): string { return __('blox_el_divider'); }
    public function icon(): string { return 'separator-horizontal'; }

    public function controls(): array
    {
        return [
            ['key' => 'style', 'type' => 'select', 'label' => __('blox_line_style'), 'default' => 'solid',
                'option_preview' => 'divider-style',
                'options' => ['solid' => __('blox_line_solid'), 'dashed' => __('blox_line_dashed'), 'dotted' => __('blox_line_dotted')]],
            ['key' => 'line_length', 'type' => 'select', 'label' => __('blox_line_length'), 'default' => 'full',
                'option_preview' => 'divider-length', 'options' => ['full' => __('blox_line_full'), 'half' => __('blox_line_half'), 'short' => __('blox_line_short')]],
            ['key' => 'line_align', 'type' => 'select', 'label' => __('blox_line_align'), 'default' => 'center',
                'option_preview' => 'divider-align', 'required' => ['line_length', '!=', 'full'],
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'width', 'type' => 'number', 'label' => __('blox_thickness_px'), 'default' => 1, 'min' => 1, 'max' => 3],
            ['key' => 'color', 'type' => 'color', 'label' => __('blox_ctl_color'), 'default' => '#e5e7eb'],
            ['key' => 'spacing', 'type' => 'select', 'label' => __('blox_section_spacing'), 'default' => 'md',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $divStyle = in_array($data['style'] ?? null, ['solid', 'dashed', 'dotted'], true) ? $data['style'] : 'solid';
        $divWidth = max(1, min(3, (int) ($data['width'] ?? 1)));
        $divColor = self::cssColor($data['color'] ?? null) ?? '#e5e7eb';
        $divSpacing = self::SPACING_MAP[$data['spacing'] ?? 'md'] ?? 'my-4';
        $lengthCss = match ($data['line_length'] ?? null) {
            'half' => ';width:50%;max-width:100%', 'short' => ';width:80px;max-width:100%', default => '',
        };
        if ($lengthCss !== '') {
            $lengthCss .= match ($data['line_align'] ?? null) {
                'left' => ';margin-left:0;margin-right:auto', 'right' => ';margin-left:auto;margin-right:0',
                default => ';margin-left:auto;margin-right:auto',
            };
        }
        return '<hr class="' . $divSpacing . ' border-0" style="border-top:' . $divWidth . 'px ' . $divStyle . ' ' . $divColor . $lengthCss . '">';
    }
}
