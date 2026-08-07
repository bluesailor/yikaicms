<?php
/** 标题元素。输出严格对齐旧 renderBlocksToHtml 的 case 'heading'。 */

declare(strict_types=1);

final class HeadingElement extends AbstractElement
{
    private const SIZE_MAP = ['h1' => 'text-3xl', 'h2' => 'text-2xl', 'h3' => 'text-xl', 'h4' => 'text-lg'];

    public function type(): string { return 'heading'; }
    public function label(): string { return __('blox_field_title_short'); }
    public function icon(): string { return 'heading'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => __('blox_seed_heading'), 'default' => '', 'placeholder' => __('blox_heading_ph')],
            [
                'key' => 'loop_field', 'type' => 'select', 'label' => __('blox_loop_text_binding'),
                'default' => 'title', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'product'),
                ],
            ],
            ['key' => 'level', 'type' => 'select', 'label' => __('blox_ctl_level'), 'default' => 'h2',
                'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4']],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
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
