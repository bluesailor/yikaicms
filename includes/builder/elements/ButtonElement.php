<?php
/** 按钮元素。对齐旧 case 'button'。 */

declare(strict_types=1);

final class ButtonElement extends AbstractElement
{
    public function type(): string { return 'button'; }
    public function label(): string { return __('blox_el_button'); }
    public function icon(): string { return 'square-rounded'; }
    public function backgroundRenderStrategy(): string { return 'native'; }
    public function compiledCssTargetTag(): ?string { return 'a'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => __('blox_ctl_btn_text'), 'default' => __('blox_el_button'),
                'dynamic_tags' => true,
                'section' => __('blox_button_section_content'), 'section_icon' => 'text-caption'],
            ['key' => 'url', 'type' => 'url', 'label' => __('blox_ctl_link_url'), 'default' => '', 'placeholder' => __('blox_ctl_link_ph'),
                'dynamic_tags' => true,
                'section' => __('blox_button_section_content'), 'section_icon' => 'text-caption'],
            ['key' => 'new_tab', 'type' => 'checkbox', 'label' => __('blox_new_tab'), 'default' => false,
                'section' => __('blox_button_section_content'), 'section_icon' => 'text-caption'],
            ...$this->linkAttributeControls(['section' => __('blox_button_section_content'), 'section_icon' => 'text-caption']),
            ['key' => 'icon', 'type' => 'icon', 'label' => __('blox_button_icon'), 'default' => 'none',
                'section' => __('blox_button_section_icon'), 'section_icon' => 'star'],
            ['key' => 'icon_position', 'type' => 'button_icon_position', 'label' => __('blox_button_icon_position'), 'default' => 'left',
                'section' => __('blox_button_section_icon'), 'section_icon' => 'star',
                'options' => ['left' => __('blox_button_icon_left'), 'right' => __('blox_button_icon_right')]],
            ['key' => 'hover_effect', 'type' => 'button_hover_effect', 'label' => __('blox_button_hover_effect'), 'default' => 'default',
                'section' => __('blox_button_section_icon'), 'section_icon' => 'star',
                'options' => [
                    'default' => __('blox_button_hover_default'),
                    'lift' => __('blox_button_hover_lift'),
                    'bright' => __('blox_button_hover_bright'),
                    'none' => __('blox_button_hover_none'),
                ]],
            [
                'key' => 'site_text_field', 'type' => 'select', 'label' => __('blox_dynamic_site_text_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('text'),
                'outside_loop_only' => true,
                'section' => __('blox_button_section_dynamic'), 'section_icon' => 'database',
            ],
            [
                'key' => 'site_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'outside_loop_only' => true,
                'required' => ['site_text_field', '!=', 'none'],
                'section' => __('blox_button_section_dynamic'), 'section_icon' => 'database',
            ],
            [
                'key' => 'site_url_field', 'type' => 'select', 'label' => __('blox_dynamic_site_url_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('url'),
                'outside_loop_only' => true,
                'section' => __('blox_button_section_dynamic'), 'section_icon' => 'database',
            ],
            [
                'key' => 'loop_text_field', 'type' => 'select', 'label' => __('blox_loop_button_text_binding'),
                'default' => 'title', 'loop_only' => true,
                'section' => __('blox_button_section_dynamic'), 'section_icon' => 'database',
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
                'section' => __('blox_button_section_dynamic'), 'section_icon' => 'database',
            ],
            [
                'key' => 'loop_url_field', 'type' => 'select', 'label' => __('blox_loop_button_url_binding'),
                'default' => 'url', 'loop_only' => true,
                'section' => __('blox_button_section_dynamic'), 'section_icon' => 'database',
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
            ['key' => 'card_position', 'type' => 'select', 'label' => __('blox_button_card_position'), 'default' => 'normal', 'tab' => 'style',
                'options' => ['normal' => __('blox_button_card_normal'), 'bottom' => __('blox_button_card_bottom')]],
            ['key' => 'variant', 'type' => 'button_style', 'label' => __('blox_button_variant'), 'default' => 'primary', 'tab' => 'style',
                'options' => [
                    'primary' => __('blox_button_variant_primary'),
                    'dark' => __('blox_button_variant_dark'),
                    'outline' => __('blox_button_variant_outline'),
                    'soft' => __('blox_button_variant_soft'),
                    'ghost' => __('blox_button_variant_ghost'),
                    'link' => __('blox_button_variant_link'),
                ]],
            ...$this->backgroundControls(),
            ['key' => 'color', 'type' => 'color', 'label' => __('blox_text_color'), 'default' => '', 'tab' => 'style'],
            ['key' => 'shape', 'type' => 'select', 'label' => __('blox_button_shape'), 'default' => 'rounded', 'tab' => 'style',
                'options' => [
                    'rounded' => __('blox_button_shape_rounded'),
                    'pill' => __('blox_button_shape_pill'),
                ]],
            // 声明式尺寸（E05 试点）：留空沿用 px-6 py-3 与形状/全站按钮设置。
            ['key' => 'btn_padding_x', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_padding_x'),
                'default' => '', 'tab' => 'style', 'min' => 0, 'max' => 96, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'padding-inline']]],
            ['key' => 'btn_padding_y', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_padding_y'),
                'default' => '', 'tab' => 'style', 'min' => 0, 'max' => 96, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'padding-block']]],
            ['key' => 'btn_radius', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_radius'),
                'default' => '', 'tab' => 'style', 'min' => 0, 'max' => 999, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'border-radius']]],
            BloxEmptyBinding::control(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $rawText = (string) ($data['text'] ?? '');
        $siteTextField = (string) ($data['site_text_field'] ?? 'none');
        if ($siteTextField !== 'none') {
            $rawText = DynamicSiteData::value($siteTextField, 'text', (string) ($data['site_fallback'] ?? ''));
        } else {
            // v1.24 动态标签：原样代入，下方 htmlspecialchars 统一转义
            $rawText = BloxDynamicTags::resolveText(DynamicSiteData::interpolate($rawText));
        }
        $rawUrl = (string) ($data['url'] ?? '#');
        $siteUrlField = (string) ($data['site_url_field'] ?? 'none');
        if ($siteUrlField !== 'none') {
            $rawUrl = DynamicSiteData::value($siteUrlField, 'url', '#');
        } else {
            $rawUrl = BloxDynamicTags::resolveText(DynamicSiteData::interpolate($rawUrl, true));
        }
        $text = htmlspecialchars($rawText);
        $iconValue = BloxIcon::normalize($data['icon'] ?? 'none', 'none');
        $iconHtml = BloxIcon::isNone($iconValue)
            ? ''
            : '<i aria-hidden="true" class="' . BloxIcon::classes($iconValue) . ' inline-block leading-none"></i>';
        $iconPosition = ($data['icon_position'] ?? 'left') === 'right' ? 'right' : 'left';
        $buttonContent = $iconPosition === 'right' ? $text . $iconHtml : $iconHtml . $text;
        // javascript: 等伪协议在这里拦（htmlspecialchars 挡不住）；非法地址退化为 #
        $href = self::safeHref($rawUrl) ?: '#';
        $url = htmlspecialchars($href);
        // 新窗口/rel/title/aria-label 与标题、图片同一规则（"0"/"false" 不再被当成开新窗口）
        $target = self::linkAttributes($href, $data['new_tab'] ?? false, $data);
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $alignClass = ['left' => '', 'center' => ' text-center', 'right' => ' text-right'][$align];
        $variant = in_array($data['variant'] ?? '', ['primary', 'dark', 'outline', 'soft', 'ghost', 'link'], true)
            ? (string) $data['variant'] : 'primary';
        $color = self::cssColor($data['color'] ?? null);
        $backgrounds = self::backgroundDeclarations($data);
        // 全局默认 → 明确局部值：局部颜色/背景优先时完整走局部路径，不消费主题变体预设。
        $themePreset = $color === null && $backgrounds === '' && class_exists(BloxDesignTheme::class)
            ? BloxDesignTheme::buttonVariantPreset($variant) : '';
        $hoverEffect = in_array($data['hover_effect'] ?? '', ['default', 'lift', 'bright', 'none'], true)
            ? (string) $data['hover_effect'] : 'default';
        $variantClass = [
            'primary' => 'bg-primary text-white',
            'dark' => 'bg-gray-900 text-white',
            'outline' => 'border border-gray-300 bg-transparent text-gray-800',
            'soft' => 'border border-gray-300 bg-gray-50 text-gray-800',
            'ghost' => 'border border-transparent bg-transparent text-gray-700',
            'link' => 'border border-transparent bg-transparent text-primary underline-offset-4',
        ][$variant];
        $defaultHoverClass = [
            'primary' => 'hover:bg-secondary',
            'dark' => 'hover:bg-black',
            'outline' => 'hover:border-gray-900',
            'soft' => 'hover:bg-gray-100 hover:border-gray-400',
            'ghost' => 'hover:bg-gray-100',
            'link' => 'hover:bg-blue-50 hover:underline',
        ][$variant];
        $hoverClass = [
            'default' => $defaultHoverClass,
            'lift' => 'hover:-translate-y-0.5 hover:shadow-md',
            'bright' => 'hover:brightness-95',
            'none' => '',
        ][$hoverEffect];
        $themeVariantClass = '';
        $inlineColor = $color !== null ? 'color:' . $color . ';' : (in_array($variant, ['primary', 'dark'], true) ? 'color:#fff;' : '');
        if ($themePreset !== '') {
            $themeVariantClass = ' yk-btn-v-' . $themePreset
                . ($hoverEffect === 'default' ? ' yk-btn-v-hover' : '')
                . (BloxDesignTheme::hasButtonVariantFocus($themePreset) ? ' focus-visible:outline-none' : '');
            if ($variant === 'primary' && BloxDesignTheme::hasButtonVariantBorder($themePreset)) {
                $variantClass .= ' border border-transparent';
            }
            // Keep text-white as the fallback, but allow preset text and hover colors to override it.
            $inlineColor = '';
        }
        $shapeClass = ($data['shape'] ?? 'rounded') === 'pill' ? 'rounded-full' : 'rounded-lg';
        // 全站按钮默认（E04）只作用于默认圆角按钮；胶囊形状视为局部明确值。未配置主题时输出不变。
        if (($data['shape'] ?? 'rounded') !== 'pill' && class_exists(BloxDesignTheme::class) && BloxDesignTheme::hasButtons()) {
            $shapeClass .= ' yk-btn-theme';
        }
        $inlineStyle = $inlineColor . $backgrounds . 'text-decoration:none';
        $positionClass = ($data['card_position'] ?? 'normal') === 'bottom' ? 'mt-auto pt-2' : 'mt-2';
        return '<div class="' . $positionClass . $alignClass . '"' . $this->animationAttrs($data)
            . '><a class="inline-flex items-center justify-center gap-2 ' . $variantClass . ' ' . $hoverClass . ' px-6 py-3 ' . $shapeClass . $themeVariantClass
            . ' transition no-underline" style="' . htmlspecialchars($inlineStyle, ENT_QUOTES) . '" href="'
            . $url . '"' . $target . ($hoverEffect === 'lift' ? ' data-yk-motion-hover' : '') . '>' . $buttonContent . '</a></div>';
    }

    public function stylesFor(array $data): array
    {
        $stylesheet = BloxIcon::stylesheet($data['icon'] ?? null);
        return $stylesheet === null ? [] : [$stylesheet];
    }
}
