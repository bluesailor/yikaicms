<?php
/** 按钮元素。对齐旧 case 'button'。 */

declare(strict_types=1);

final class ButtonElement extends AbstractElement
{
    public function type(): string { return 'button'; }
    public function label(): string { return __('blox_el_button'); }
    public function icon(): string { return 'square-rounded'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => __('blox_ctl_btn_text'), 'default' => __('blox_el_button')],
            ['key' => 'url', 'type' => 'text', 'label' => __('blox_ctl_link_url'), 'default' => '', 'placeholder' => __('blox_ctl_link_ph')],
            ['key' => 'new_tab', 'type' => 'checkbox', 'label' => __('blox_new_tab'), 'default' => false],
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
                'key' => 'align', 'type' => 'select', 'label' => __('blox_h_position'), 'default' => 'left', 'tab' => 'style',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')],
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
