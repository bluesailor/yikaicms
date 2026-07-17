<?php
/** 间距元素。对齐旧 case 'spacer'。 */

declare(strict_types=1);

final class SpacerElement extends AbstractElement
{
    /** 响应式三档映射（[基类, md:类, lg:类]，字面量写全供 Tailwind 扫描） */
    private const SIZE_MAP = [
        'sm' => ['h-4', 'md:h-4', 'lg:h-4'],
        'md' => ['h-8', 'md:h-8', 'lg:h-8'],
        'lg' => ['h-16', 'md:h-16', 'lg:h-16'],
        'xl' => ['h-24', 'md:h-24', 'lg:h-24'],
    ];

    public function type(): string { return 'spacer'; }
    public function label(): string { return '间距'; }
    public function icon(): string { return 'arrow-autofit-height'; }
    public function category(): string { return 'layout'; }

    public function controls(): array
    {
        return [
            ['key' => 'size', 'type' => 'select', 'label' => '高度', 'default' => 'md', 'responsive' => true,
                'options' => ['sm' => '小 (16px)', 'md' => '中 (32px)', 'lg' => '大 (64px)', 'xl' => '超大 (96px)']],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $size = $this->resp($data['size'] ?? 'md', self::SIZE_MAP, 'md');
        return '<div class="' . $size . '"></div>';
    }
}
