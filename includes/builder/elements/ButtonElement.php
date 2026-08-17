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
                'key' => 'site_text_field', 'type' => 'select', 'label' => __('blox_dynamic_site_text_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('text'),
                'outside_loop_only' => true, 'advanced' => true,
            ],
            [
                'key' => 'site_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'outside_loop_only' => true, 'advanced' => true,
                'required' => ['site_text_field', '!=', 'none'],
            ],
            [
                'key' => 'site_url_field', 'type' => 'select', 'label' => __('blox_dynamic_site_url_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('url'),
                'outside_loop_only' => true, 'advanced' => true,
            ],
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
                'key' => 'loop_text_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'loop_only' => true, 'advanced' => true,
                'required' => ['loop_text_field', '!=', 'none'],
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
            ['key' => 'variant', 'type' => 'select', 'label' => __('blox_button_variant'), 'default' => 'primary', 'tab' => 'style',
                'options' => [
                    'primary' => __('blox_button_variant_primary'),
                    'dark' => __('blox_button_variant_dark'),
                    'outline' => __('blox_button_variant_outline'),
                ]],
            ['key' => 'shape', 'type' => 'select', 'label' => __('blox_button_shape'), 'default' => 'rounded', 'tab' => 'style',
                'options' => [
                    'rounded' => __('blox_button_shape_rounded'),
                    'pill' => __('blox_button_shape_pill'),
                ]],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $rawText = (string) ($data['text'] ?? '');
        $siteTextField = (string) ($data['site_text_field'] ?? 'none');
        if ($siteTextField !== 'none') {
            $rawText = DynamicSiteData::value($siteTextField, 'text', (string) ($data['site_fallback'] ?? ''));
        }
        $rawUrl = (string) ($data['url'] ?? '#');
        $siteUrlField = (string) ($data['site_url_field'] ?? 'none');
        if ($siteUrlField !== 'none') {
            $rawUrl = DynamicSiteData::value($siteUrlField, 'url', '#');
        }
        $text = htmlspecialchars($rawText);
        $url = htmlspecialchars($rawUrl);
        $target = !empty($data['new_tab']) ? ' target="_blank" rel="noopener"' : '';
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $alignClass = ['left' => '', 'center' => ' text-center', 'right' => ' text-right'][$align];
        $variant = in_array($data['variant'] ?? '', ['primary', 'dark', 'outline'], true)
            ? (string) $data['variant'] : 'primary';
        $variantClass = [
            'primary' => 'bg-primary hover:bg-secondary text-white',
            'dark' => 'bg-gray-900 hover:bg-black text-white',
            'outline' => 'border border-gray-300 bg-transparent text-gray-800 hover:border-gray-900',
        ][$variant];
        $shapeClass = ($data['shape'] ?? 'rounded') === 'pill' ? 'rounded-full' : 'rounded-lg';
        $inlineColor = $variant === 'outline' ? '' : 'color:#fff;';
        return '<div class="mt-2' . $alignClass . '"' . $this->animationAttrs($data)
            . '><a class="inline-block ' . $variantClass . ' px-6 py-3 ' . $shapeClass
            . ' transition no-underline" style="' . $inlineColor . 'text-decoration:none" href="'
            . $url . '"' . $target . '>' . $text . '</a></div>';
    }
}
