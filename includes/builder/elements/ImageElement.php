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
            ['key' => 'link_url', 'type' => 'url', 'label' => __('blox_ctl_link'), 'default' => '',
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
        $dynamicField = (string) ($data['_responsive_image_field'] ?? '');
        $dynamicFallback = (string) ($data['_responsive_image_fallback'] ?? '');
        if ($dynamicField !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $dynamicField) === 1) {
            $imageAttrs = '{yk:image-attrs name=' . $dynamicField . ' size=medium sizes="100vw"';
            if ($dynamicFallback !== '') {
                $imageAttrs .= ' fallback=' . rawurlencode($dynamicFallback);
            }
            $imageAttrs .= ' /}';
        } else {
            $rawSrc = UrlPolicy::image($rawSrc);
            $imageAttrs = responsiveImageAttributes($rawSrc, 'medium', '100vw');
        }
        $alt = htmlspecialchars((string) ($data['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($rawSrc === '' && $dynamicField === '') {
            return '';
        }
        $animationAttrs = $this->animationAttrs($data);
        $clickAction = $data['click_action'] ?? '';
        $imgTag = '<img class="w-full rounded-lg" ' . $imageAttrs . ' alt="' . $alt . '" loading="lazy" decoding="async">';
        if ($clickAction === 'lightbox') {
            // 灯箱的 href 是可点击链接，须过伪协议校验；src 不合法则退化为普通图片
            $lightboxHref = self::safeHref($rawSrc);
            if ($lightboxHref !== '') {
                return '<a href="' . htmlspecialchars($lightboxHref) . '" data-lightbox class="block cursor-zoom-in"' . $animationAttrs . '>' . $imgTag . '</a>';
            }
        }
        if ($clickAction === 'link' && !empty($data['link_url'])) {
            // javascript: 等伪协议在这里拦；非法地址退化为普通图片
            $linkUrl = self::safeHref($data['link_url']);
            if ($linkUrl !== '') {
                $target = !empty($data['link_new_tab']) ? ' target="_blank" rel="noopener"' : '';
                return '<a href="' . htmlspecialchars($linkUrl) . '"' . $target . ' class="block"' . $animationAttrs . '>' . $imgTag . '</a>';
            }
        }
        return '<img class="w-full rounded-lg" ' . $imageAttrs . ' alt="' . $alt
            . '" loading="lazy" decoding="async"' . $animationAttrs . '>';
    }
}
