<?php
/** 图片元素。对齐旧 case 'image'（含 lightbox / link 点击行为；无 src 返回空）。 */

declare(strict_types=1);

final class ImageElement extends AbstractElement
{
    public function type(): string { return 'image'; }
    public function label(): string { return __('blox_el_image'); }
    public function icon(): string { return 'photo'; }
    public function category(): string { return 'media'; }

    // image 由构建器选图/上传组件接管（hasCustomUI），此处仅供默认值 / 元数据
    public function controls(): array
    {
        return [
            ['key' => 'src', 'type' => 'image', 'label' => __('blox_el_image'), 'default' => ''],
            ['key' => 'alt', 'type' => 'text', 'label' => __('blox_ctl_desc'), 'default' => ''],
            [
                'key' => 'site_image_field', 'type' => 'select', 'label' => __('blox_dynamic_site_image_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('image'),
                'outside_loop_only' => true, 'advanced' => true,
            ],
            [
                'key' => 'loop_field', 'type' => 'select', 'label' => __('blox_loop_image_binding'),
                'default' => 'cover', 'loop_only' => true,
                'options' => DynamicListItemSchema::fieldOptions('image', 'content'),
                'source_options' => [
                    'content' => DynamicListItemSchema::fieldOptions('image', 'content'),
                    'product' => DynamicListItemSchema::fieldOptions('image', 'product'),
                ],
            ],
            [
                'key' => 'loop_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'loop_only' => true, 'advanced' => true,
                'required' => ['loop_field', '!=', 'none'],
            ],
            [
                'key' => 'loop_alt_field', 'type' => 'select', 'label' => __('blox_loop_alt_binding'),
                'default' => 'title', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'product'),
                ],
            ],
            [
                'key' => 'loop_alt_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'loop_only' => true, 'advanced' => true,
                'required' => ['loop_alt_field', '!=', 'none'],
            ],
            [
                'key' => 'loop_link_field', 'type' => 'select', 'label' => __('blox_loop_link_binding'),
                'default' => 'none', 'loop_only' => true,
                'options' => DynamicListItemSchema::fieldOptions('link', 'content'),
                'source_options' => [
                    'content' => DynamicListItemSchema::fieldOptions('link', 'content'),
                    'product' => DynamicListItemSchema::fieldOptions('link', 'product'),
                ],
            ],
            ['key' => 'click_action', 'type' => 'select', 'label' => __('blox_click_action'), 'default' => '',
                'options' => ['' => __('blox_click_none'), 'lightbox' => __('blox_click_lightbox'), 'link' => __('blox_click_link')]],
            ['key' => 'link_url', 'type' => 'text', 'label' => __('blox_ctl_link'), 'default' => '',
                'visible_when' => ['terms' => [['click_action', '=', 'link']]]],
            ['key' => 'link_new_tab', 'type' => 'checkbox', 'label' => __('blox_new_tab_short'), 'default' => false,
                'visible_when' => ['terms' => [['click_action', '=', 'link']]]],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $rawSrc = (string) ($data['src'] ?? '');
        $siteImageField = (string) ($data['site_image_field'] ?? 'none');
        if ($siteImageField !== 'none') {
            $rawSrc = DynamicSiteData::value($siteImageField, 'image');
        }
        $src = htmlspecialchars($rawSrc);
        $alt = htmlspecialchars($data['alt'] ?? '');
        if (!$src) {
            return '';
        }
        $animationAttrs = $this->animationAttrs($data);
        $clickAction = $data['click_action'] ?? '';
        $imgTag = '<img class="w-full rounded-lg" src="' . $src . '" alt="' . $alt . '" loading="lazy">';
        if ($clickAction === 'lightbox') {
            return '<a href="' . $src . '" data-lightbox class="block cursor-zoom-in"' . $animationAttrs . '>' . $imgTag . '</a>';
        }
        if ($clickAction === 'link' && !empty($data['link_url'])) {
            $linkUrl = htmlspecialchars($data['link_url']);
            $target = !empty($data['link_new_tab']) ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . $linkUrl . '"' . $target . ' class="block"' . $animationAttrs . '>' . $imgTag . '</a>';
        }
        return '<img class="w-full rounded-lg" src="' . $src . '" alt="' . $alt . '" loading="lazy"' . $animationAttrs . '>';
    }
}
