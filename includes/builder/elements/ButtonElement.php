<?php
/** 按钮元素。对齐旧 case 'button'。 */

declare(strict_types=1);

final class ButtonElement extends AbstractElement
{
    public function type(): string { return 'button'; }
    public function label(): string { return '按钮'; }
    public function icon(): string { return 'square-rounded'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => '按钮文字', 'default' => '按钮'],
            ['key' => 'url', 'type' => 'text', 'label' => '链接地址', 'default' => '', 'placeholder' => 'https:// 或 /path'],
            ['key' => 'new_tab', 'type' => 'checkbox', 'label' => '新窗口打开', 'default' => false],
            [
                'key' => 'loop_text_field', 'type' => 'select', 'label' => __('blox_loop_button_text_binding'),
                'default' => 'title', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'product'),
                ],
            ],
            [
                'key' => 'loop_url_field', 'type' => 'select', 'label' => __('blox_loop_button_url_binding'),
                'default' => 'url', 'loop_only' => true,
                'options' => DynamicListItemSchema::fieldOptions('link', 'content'),
                'source_options' => [
                    'content' => DynamicListItemSchema::fieldOptions('link', 'content'),
                    'product' => DynamicListItemSchema::fieldOptions('link', 'product'),
                ],
            ],
            [
                'key' => 'align', 'type' => 'select', 'label' => '水平位置', 'default' => 'left', 'tab' => 'style',
                'options' => ['left' => '左对齐', 'center' => '居中', 'right' => '右对齐'],
                'option_icons' => ['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right'],
            ],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $text = htmlspecialchars($data['text'] ?? '');
        $url = htmlspecialchars($data['url'] ?? '#');
        $target = !empty($data['new_tab']) ? ' target="_blank" rel="noopener"' : '';
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $alignClass = ['left' => '', 'center' => ' text-center', 'right' => ' text-right'][$align];
        return '<div class="mt-2' . $alignClass . '"' . $this->animationAttrs($data) . '><a class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg transition no-underline" style="color:#fff;text-decoration:none" href="' . $url . '"' . $target . '>' . $text . '</a></div>';
    }
}
