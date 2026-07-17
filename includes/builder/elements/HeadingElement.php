<?php
/** 标题元素。输出严格对齐旧 renderBlocksToHtml 的 case 'heading'。 */

declare(strict_types=1);

final class HeadingElement extends AbstractElement
{
    private const SIZE_MAP = ['h1' => 'text-3xl', 'h2' => 'text-2xl', 'h3' => 'text-xl', 'h4' => 'text-lg'];

    public function type(): string { return 'heading'; }
    public function label(): string { return '标题'; }
    public function icon(): string { return 'heading'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => '标题文字', 'default' => '', 'placeholder' => '输入标题...'],
            ['key' => 'level', 'type' => 'select', 'label' => '级别', 'default' => 'h2',
                'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4']],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $level = in_array($data['level'] ?? '', ['h1', 'h2', 'h3', 'h4']) ? $data['level'] : 'h2';
        $size = self::SIZE_MAP[$level];
        return '<' . $level . ' class="' . $size . ' font-bold mb-4">' . htmlspecialchars($data['text'] ?? '') . '</' . $level . '>';
    }
}
