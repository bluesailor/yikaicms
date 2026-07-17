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

    public function controls(): array
    {
        return [
            ['key' => 'size', 'type' => 'select', 'label' => '高度', 'default' => 'md',
                'options' => ['sm' => '小 (16px)', 'md' => '中 (32px)', 'lg' => '大 (64px)', 'xl' => '超大 (96px)']],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $size = self::SIZE_MAP[$data['size'] ?? 'md'] ?? 'h-8';
        return '<div class="' . $size . '"></div>';
    }
}
