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
            [
                'key' => 'loop_field', 'type' => 'select', 'label' => __('blox_loop_text_binding'),
                'default' => 'title', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'product'),
                ],
            ],
            ['key' => 'level', 'type' => 'select', 'label' => '级别', 'default' => 'h2',
                'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4']],
            ['key' => 'align', 'type' => 'select', 'label' => '对齐', 'default' => 'left',
                'options' => ['left' => '左对齐', 'center' => '居中', 'right' => '右对齐']],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $level = in_array($data['level'] ?? '', ['h1', 'h2', 'h3', 'h4']) ? $data['level'] : 'h2';
        $size = self::SIZE_MAP[$level];
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $alignCls = ['left' => '', 'center' => ' text-center', 'right' => ' text-right'][$align];
        return '<' . $level . ' class="' . $size . ' font-bold mb-4' . $alignCls . '"' . $this->animationAttrs($data) . '>' . htmlspecialchars($data['text'] ?? '') . '</' . $level . '>';
    }
}
