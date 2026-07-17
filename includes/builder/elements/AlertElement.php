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
    public function label(): string { return '提示框'; }
    public function icon(): string { return 'alert-circle'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'textarea', 'label' => '内容', 'default' => '', 'rows' => 2],
            ['key' => 'level', 'type' => 'select', 'label' => '类型', 'default' => 'info',
                'options' => ['info' => '信息', 'success' => '成功', 'warning' => '警告', 'error' => '错误']],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $cls = self::STYLE[$data['level'] ?? 'info'] ?? self::STYLE['info'];
        return '<div class="border rounded-lg px-4 py-3 my-2 ' . $cls . '">' . htmlspecialchars($data['text'] ?? '') . '</div>';
    }
}
