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
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_tab_content'), 'default' => '', 'rows' => 2],
            ['key' => 'level', 'type' => 'select', 'label' => __('blox_tpl_col_type'), 'default' => 'info',
                'options' => ['info' => __('blox_alert_info'), 'success' => __('blox_alert_success'), 'warning' => __('blox_alert_warning'), 'error' => __('blox_alert_error')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $cls = self::STYLE[$data['level'] ?? 'info'] ?? self::STYLE['info'];
        return '<div class="border rounded-lg px-4 py-3 my-2 ' . $cls . '">' . htmlspecialchars($data['text'] ?? '') . '</div>';
    }
}
