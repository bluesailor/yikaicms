<?php
/** 标题元素。输出严格对齐旧 renderBlocksToHtml 的 case 'heading'。 */

declare(strict_types=1);

final class HeadingElement extends AbstractElement
{
    private const LEVEL_SIZE_MAP = [
        'h1' => ['text-3xl', 'md:text-3xl', 'lg:text-3xl'],
        'h2' => ['text-2xl', 'md:text-2xl', 'lg:text-2xl'],
        'h3' => ['text-xl', 'md:text-xl', 'lg:text-xl'],
        'h4' => ['text-lg', 'md:text-lg', 'lg:text-lg'],
        'h5' => ['text-base', 'md:text-base', 'lg:text-base'],
        'h6' => ['text-sm', 'md:text-sm', 'lg:text-sm'],
    ];
    private const VISUAL_SIZE_MAP = [
        'sm' => ['text-base', 'md:text-base', 'lg:text-base'],
        'md' => ['text-lg', 'md:text-lg', 'lg:text-lg'],
        'lg' => ['text-xl', 'md:text-xl', 'lg:text-xl'],
        'xl' => ['text-2xl', 'md:text-2xl', 'lg:text-2xl'],
        '2xl' => ['text-3xl', 'md:text-3xl', 'lg:text-3xl'],
        '3xl' => ['text-4xl', 'md:text-4xl', 'lg:text-4xl'],
        '4xl' => ['text-5xl', 'md:text-5xl', 'lg:text-5xl'],
        '5xl' => ['text-6xl', 'md:text-6xl', 'lg:text-6xl'],
        '6xl' => ['text-7xl', 'md:text-7xl', 'lg:text-7xl'],
        'display' => ['text-8xl', 'md:text-8xl', 'lg:text-8xl'],
    ];

    public function type(): string { return 'heading'; }
    public function label(): string { return __('blox_field_title_short'); }
    public function icon(): string { return 'heading'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_field_title_short'), 'default' => '', 'maxlength' => 2000, 'placeholder' => __('blox_heading_ph')],
            [
                'key' => 'site_field', 'type' => 'select', 'label' => __('blox_dynamic_site_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('text'),
                'outside_loop_only' => true, 'advanced' => true,
            ],
            [
                'key' => 'site_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'outside_loop_only' => true, 'advanced' => true,
                'required' => ['site_field', '!=', 'none'],
            ],
            [
                'key' => 'loop_field', 'type' => 'select', 'label' => __('blox_loop_text_binding'),
                'default' => 'title', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('title', 'product'),
                ],
            ],
            [
                'key' => 'loop_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'loop_only' => true, 'advanced' => true,
                'required' => ['loop_field', '!=', 'none'],
            ],
            ['key' => 'level', 'type' => 'select', 'label' => __('blox_ctl_level'), 'default' => 'h2',
                'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6']],
            ['key' => 'url', 'type' => 'url', 'label' => __('blox_ctl_link'), 'default' => '', 'placeholder' => __('blox_ctl_link_ph')],
            ['key' => 'new_tab', 'type' => 'checkbox', 'label' => __('blox_new_tab'), 'default' => false],
            ['key' => 'site_url_field', 'type' => 'select', 'label' => __('blox_dynamic_site_url_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('url'), 'outside_loop_only' => true],
            ['key' => 'loop_url_field', 'type' => 'select', 'label' => __('blox_loop_button_url_binding'),
                'default' => 'none', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('link', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('link', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('link', 'product'),
                ]],
            ['key' => 'html_id', 'type' => 'text', 'label' => __('blox_heading_anchor'), 'default' => '', 'maxlength' => 64,
                'outside_loop_only' => true, 'placeholder' => 'services'],
            ['key' => 'visual_size', 'type' => 'select', 'label' => __('blox_font_size'), 'default' => 'auto',
                'tab' => 'style', 'responsive' => true,
                'options' => [
                    'auto' => __('blox_heading_size_auto'), 'sm' => '16px', 'md' => '18px',
                    'lg' => '20px', 'xl' => '24px', '2xl' => '30px', '3xl' => '36px',
                    '4xl' => '48px', '5xl' => '60px', '6xl' => '72px', 'display' => '96px',
                ]],
            // 声明式排版（E05 试点）：留空沿用视觉字号档位与全站排版，填写后以实例样式覆盖。
            ['key' => 'type_font_size', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_font_size'),
                'default' => '', 'tab' => 'style', 'responsive' => true, 'min' => 8, 'max' => 160, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'font-size']]],
            ['key' => 'type_line_height', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_line_height'),
                'default' => '', 'tab' => 'style', 'min' => 0.8, 'max' => 3, 'step' => 0.1,
                'css' => [['property' => 'line-height']]],
            ['key' => 'color', 'type' => 'color', 'label' => __('blox_text_color'), 'default' => '', 'tab' => 'style'],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left', 'tab' => 'style',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')],
                'option_icons' => ['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right']],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $level = in_array($data['level'] ?? '', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $data['level'] : 'h2';
        $sizeMap = ['auto' => self::LEVEL_SIZE_MAP[$level]] + self::VISUAL_SIZE_MAP;
        $size = $this->resp($data['visual_size'] ?? 'auto', $sizeMap, 'auto');
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $alignCls = ['left' => '', 'center' => ' text-center', 'right' => ' text-right'][$align];
        $text = (string) ($data['text'] ?? '');
        $siteField = (string) ($data['site_field'] ?? 'none');
        if ($siteField !== 'none') {
            $text = DynamicSiteData::value($siteField, 'text', (string) ($data['site_fallback'] ?? ''));
        } else {
            $text = DynamicSiteData::interpolate($text);
        }
        $color = self::cssColor($data['color'] ?? null);
        $style = $color !== null ? ' style="color:' . htmlspecialchars($color, ENT_QUOTES) . ';"' : '';
        $id = BloxDocumentPipeline::normalizeSectionAnchorId($data['html_id'] ?? '');
        $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"' : '';
        $rawUrl = (string) ($data['url'] ?? '');
        $siteUrlField = (string) ($data['site_url_field'] ?? 'none');
        if ($siteUrlField !== 'none') $rawUrl = DynamicSiteData::value($siteUrlField, 'url');
        else $rawUrl = DynamicSiteData::interpolate($rawUrl, true);
        $url = self::safeHref($rawUrl);
        $text = str_replace(["\r\n", "\r", "\n"], '<br>', htmlspecialchars($text));
        if ($url !== '') {
            $target = BloxValueSanitizer::truthy($data['new_tab'] ?? false) ? ' target="_blank" rel="noopener noreferrer"' : '';
            $text = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="yk-heading-link"' . $target . '>' . $text . '</a>';
        }
        // 全站排版（E04）只接管未设置视觉字号的标题；未配置主题时不追加任何类，输出逐字节不变。
        $visualSize = $data['visual_size'] ?? 'auto';
        $autoSize = is_array($visualSize)
            ? array_filter($visualSize, static fn(mixed $value): bool => $value !== 'auto' && $value !== '' && $value !== null) === []
            : in_array($visualSize, ['auto', '', null], true);
        $themeCls = $autoSize && class_exists(BloxDesignTheme::class) && BloxDesignTheme::hasTypography($level) ? ' yk-type-' . $level : '';
        return '<' . $level . ' class="' . $size . ' font-bold mb-4' . $alignCls . ($id !== '' ? ' yk-blox-anchor' : '') . $themeCls . '"' . $style
            . $idAttr . $this->animationAttrs($data) . '>' . $text . '</' . $level . '>';
    }
}
