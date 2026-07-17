<?php
/** 分隔线元素。对齐旧 case 'divider'。 */

declare(strict_types=1);

final class DividerElement extends AbstractElement
{
    private const SPACING_MAP = ['sm' => 'my-2', 'md' => 'my-4', 'lg' => 'my-8'];

    public function type(): string { return 'divider'; }
    public function label(): string { return '分隔线'; }
    public function icon(): string { return 'separator-horizontal'; }

    public function render(array $data, string $children = ''): string
    {
        $divStyle = htmlspecialchars($data['style'] ?? 'solid');
        $divWidth = max(1, min(3, (int) ($data['width'] ?? 1)));
        $divColor = htmlspecialchars($data['color'] ?? '#e5e7eb');
        $divSpacing = self::SPACING_MAP[$data['spacing'] ?? 'md'] ?? 'my-4';
        return '<hr class="' . $divSpacing . ' border-0" style="border-top:' . $divWidth . 'px ' . $divStyle . ' ' . $divColor . '">';
    }
}
