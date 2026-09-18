<?php
/** 提示框元素（info/success/warning/error 配色）。schema 驱动，后台自动生成 UI。 */

declare(strict_types=1);

final class AlertElement extends AbstractElement
{
    private const STYLE = [
        'info'    => 'bg-blue-50 text-blue-700 border-blue-200',
        'success' => 'bg-green-50 text-green-700 border-green-200',
        'warning' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
        'error'   => 'bg-red-50 text-red-700 border-red-200',
    ];

    public function type(): string { return 'alert'; }
    public function label(): string { return __('blox_el_alert'); }
    public function icon(): string { return 'alert-circle'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_tab_content'), 'default' => '', 'rows' => 2,
                'compact_richtext' => true, 'format_key' => 'text_format'],
            ['key' => 'text_format', 'type' => 'select', 'label' => __('blox_desc_format'), 'default' => 'plain',
                'editor_hidden' => true, 'options' => ['plain' => __('blox_desc_plain'), 'html' => 'HTML']],
            ['key' => 'level', 'type' => 'select', 'label' => __('blox_tpl_col_type'), 'default' => 'info',
                'option_preview' => 'alert-level',
                'option_icons' => ['info' => 'info-circle', 'success' => 'circle-check', 'warning' => 'alert-triangle', 'error' => 'circle-x'],
                'options' => ['info' => __('blox_alert_info'), 'success' => __('blox_alert_success'), 'warning' => __('blox_alert_warning'), 'error' => __('blox_alert_error')]],
            ['key' => 'alert_style', 'type' => 'select', 'label' => __('blox_alert_appearance'), 'default' => 'soft', 'tab' => 'style',
                'option_preview' => 'alert-style', 'options' => ['soft' => __('blox_surface_soft'), 'outline' => __('blox_surface_outline'), 'solid' => __('blox_surface_solid')]],
            ['key' => 'show_icon', 'type' => 'checkbox', 'label' => __('blox_alert_show_icon'), 'default' => false, 'tab' => 'style'],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $level = in_array($data['level'] ?? null, ['info', 'success', 'warning', 'error'], true) ? $data['level'] : 'info';
        $surface = in_array($data['alert_style'] ?? null, ['soft', 'outline', 'solid'], true) ? $data['alert_style'] : 'soft';
        $showIcon = in_array($data['show_icon'] ?? false, [true, 1, '1'], true);
        $rich = ($data['text_format'] ?? null) === 'html';
        $raw = is_scalar($data['text'] ?? null) ? (string) $data['text'] : '';
        $text = $rich ? HtmlPolicy::description($raw) : htmlspecialchars($raw);
        $cls = self::STYLE[$level];
        if ($surface === 'soft' && !$showIcon && !$rich) {
            return '<div class="border rounded-lg px-4 py-3 my-2 ' . $cls . '">' . $text . '</div>';
        }
        $html = '<div class="border rounded-lg px-4 py-3 my-2 ' . $cls . ' yk-alert yk-alert-' . $level . ' yk-alert-' . $surface . '">';
        if ($showIcon) {
            $icon = match ($level) {
                'success' => 'circle-check', 'warning' => 'alert-triangle', 'error' => 'circle-x', default => 'info-circle',
            };
            $html .= '<i class="' . BloxIcon::classes($icon) . ' yk-alert-icon" aria-hidden="true"></i>';
        }
        return $html . '<div class="yk-alert-copy' . ($rich ? ' yk-description' : '') . '">' . $text . '</div></div>';
    }
}
