<?php
/** 间距元素。对齐旧 case 'spacer'。 */

declare(strict_types=1);

final class SpacerElement extends AbstractElement
{
    private const SIZE_MAP = ['sm' => 'h-4', 'md' => 'h-8', 'lg' => 'h-16', 'xl' => 'h-24'];

    public function type(): string { return 'spacer'; }
    public function label(): string { return '间距'; }
    public function icon(): string { return 'arrow-autofit-height'; }
    public function category(): string { return 'layout'; }

    public function render(array $data, string $children = ''): string
    {
        $size = self::SIZE_MAP[$data['size'] ?? 'md'] ?? 'h-8';
        return '<div class="' . $size . '"></div>';
    }
}
