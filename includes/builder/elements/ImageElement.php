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
                'outside_loop_only' => true,
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
            // 同名分组的图片在灯箱里可前后切换（E05 切片 C）。留空＝只看这一张。
            ['key' => 'lightbox_group', 'type' => 'text', 'label' => __('blox_lightbox_group'), 'default' => '',
                'visible_when' => ['terms' => [['click_action', '=', 'lightbox']]]],
            ['key' => 'lightbox_caption', 'type' => 'text', 'label' => __('blox_lightbox_caption'), 'default' => '',
                'visible_when' => ['terms' => [['click_action', '=', 'lightbox']]]],
            ...BloxImageFraming::controls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $rawSrc = (string) ($data['src'] ?? '');
        $siteImageField = (string) ($data['site_image_field'] ?? 'none');
        if ($siteImageField !== 'none') {
            $rawSrc = DynamicSiteData::value($siteImageField, 'image');
        } else {
            // v1.24-③ 结构化属性绑定：src 吃 {{loop.cover}} 等动态标签（容器 Loop 卡片图）。
            // 解析产物随后过 UrlPolicy::storedImage / safeHref，与静态路径同一安全管线。
            $rawSrc = BloxDynamicTags::resolveText(DynamicSiteData::interpolate($rawSrc, true));
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
            $rawSrc = UrlPolicy::storedImage($rawSrc);
            $imageAttrs = responsiveImageAttributes($rawSrc, 'medium', '100vw');
        }
        $alt = htmlspecialchars(BloxDynamicTags::resolveText((string) ($data['alt'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($rawSrc === '' && $dynamicField === '') {
            return '';
        }
        $animationAttrs = $this->animationAttrs($data);
        $frameStyle = BloxImageFraming::standaloneStyle($data);
        $clickAction = $data['click_action'] ?? '';
        $imgTag = '<img class="w-full rounded-lg" ' . $imageAttrs . ' alt="' . $alt . '" loading="lazy" decoding="async"' . $frameStyle . '>';
        if ($clickAction === 'lightbox') {
            // 灯箱的 href 是可点击链接，须过伪协议校验；src 不合法则退化为普通图片
            $lightboxHref = self::safeHref($rawSrc);
            if ($lightboxHref !== '') {
                // 按需加载：没有灯箱图片的页面不会引入这两个文件
                BloxAssetCollector::addStyle('/assets/css/blox-lightbox.css');
                BloxAssetCollector::addScript('/assets/js/blox-lightbox.js');
                // 分组名让同组图片可以前后切换；留空则只看这一张。
                // 值取自作者填写的分组，经白名单收敛——它会进选择器，不能带引号或空白。
                $groupRaw = trim((string) ($data['lightbox_group'] ?? ''));
                $group = preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $groupRaw) === 1 ? $groupRaw : '';
                $groupAttr = $group === '' ? ' data-lightbox' : ' data-lightbox="' . htmlspecialchars($group) . '"';
                $captionRaw = trim(BloxDynamicTags::resolveText((string) ($data['lightbox_caption'] ?? '')));
                $captionAttr = $captionRaw === ''
                    ? ''
                    : ' data-caption="' . htmlspecialchars(mb_substr($captionRaw, 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                return '<a href="' . htmlspecialchars($lightboxHref) . '"' . $groupAttr . $captionAttr
                    . ' class="block cursor-zoom-in"' . $animationAttrs . '>' . $imgTag . '</a>';
            }
        }
        if ($clickAction === 'link' && !empty($data['link_url'])) {
            // javascript: 等伪协议在这里拦；非法地址退化为普通图片（动态标签先解析再校验）
            $linkUrl = self::safeHref(BloxDynamicTags::resolveText(
                DynamicSiteData::interpolate((string) $data['link_url'], true)
            ));
            if ($linkUrl !== '') {
                $target = !empty($data['link_new_tab']) ? ' target="_blank" rel="noopener"' : '';
                return '<a href="' . htmlspecialchars($linkUrl) . '"' . $target . ' class="block"' . $animationAttrs . '>' . $imgTag . '</a>';
            }
        }
        return '<img class="w-full rounded-lg" ' . $imageAttrs . ' alt="' . $alt
            . '" loading="lazy" decoding="async"' . $frameStyle . $animationAttrs . '>';
    }

}
