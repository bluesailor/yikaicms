<?php
/** 卡片元素：图 + 标题 + 文 + 可选链接。schema 驱动（图用 URL 文本框）。 */

declare(strict_types=1);

final class CardElement extends AbstractElement
{
    public function type(): string { return 'card'; }
    public function label(): string { return __('blox_el_card'); }
    public function icon(): string { return 'square-rounded'; }
    public function category(): string { return 'media'; }
    /** 通用背景：root——渲染后由 BlockRenderer 注入首标签（<div> 或链接态 <a> 均适用） */
    public function backgroundRenderStrategy(): string { return 'root'; }

    public function controls(): array
    {
        return [
            ['key' => 'image', 'type' => 'image', 'label' => __('blox_image_url'), 'default' => ''],
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_field_title_short'), 'default' => ''],
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_desc'), 'default' => '', 'rows' => 2,
                'compact_richtext' => true, 'format_key' => 'text_format'],
            ['key' => 'text_format', 'type' => 'select', 'label' => __('blox_desc_format'), 'default' => 'plain',
                'editor_hidden' => true, 'options' => ['plain' => __('blox_desc_plain'), 'html' => 'HTML']],
            ['key' => 'link', 'type' => 'url', 'label' => __('blox_ctl_link'), 'default' => '', 'placeholder' => __('blox_empty_unclickable')],
            ['key' => 'card_layout', 'type' => 'select', 'label' => __('blox_card_layout'), 'default' => 'top', 'tab' => 'style',
                'option_preview' => 'card-layout',
                'options' => ['top' => __('blox_card_layout_top'), 'side' => __('blox_card_layout_side'), 'text' => __('blox_card_layout_text')]],
            ['key' => 'card_surface', 'type' => 'select', 'label' => __('blox_card_surface'), 'default' => 'shadow', 'tab' => 'style',
                'option_preview' => 'card-surface',
                'options' => ['plain' => __('blox_card_surface_plain'), 'border' => __('blox_card_surface_border'), 'shadow' => __('blox_card_surface_shadow')]],
            ['key' => 'card_hover', 'type' => 'select', 'label' => __('blox_button_hover_effect'), 'default' => 'default', 'tab' => 'style',
                'option_preview' => 'card-hover', 'required' => ['link', '!=', ''],
                'option_requirements' => ['zoom' => ['visible_when' => ['terms' => [['image', 'not_empty'], ['card_layout', '!=', 'text']]]]],
                'option_hints' => ['zoom' => __('blox_card_hover_zoom_hint')],
                'options' => ['default' => __('blox_button_hover_default'), 'lift' => __('blox_button_hover_lift'), 'zoom' => __('blox_card_hover_zoom'), 'none' => __('blox_button_hover_none')]],
            ...BloxImageFraming::controls(true),
            ...$this->backgroundControls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $image = UrlPolicy::storedImage($data['image'] ?? '');
        $title = htmlspecialchars($data['title'] ?? '');
        $richDescription = ($data['text_format'] ?? null) === 'html';
        $rawText = is_scalar($data['text'] ?? null) ? (string) $data['text'] : '';
        // javascript: 等伪协议在这里拦；非法地址视同未填——退化为不可点击的 div
        $link = htmlspecialchars(self::safeHref($data['link'] ?? ''));
        $text = $richDescription ? HtmlPolicy::description($rawText, $link === '') : htmlspecialchars($rawText);
        $layout = in_array($data['card_layout'] ?? null, ['top', 'side', 'text'], true) ? $data['card_layout'] : 'top';
        $surface = in_array($data['card_surface'] ?? null, ['plain', 'border', 'shadow'], true) ? $data['card_surface'] : 'shadow';
        $hover = in_array($data['card_hover'] ?? null, ['default', 'lift', 'zoom', 'none'], true) ? $data['card_hover'] : 'default';
        $ratio = BloxImageFraming::ratio($data, true);
        $customFrame = $image !== '' && $layout !== 'text' && $ratio !== 'default';
        $enhanced = $layout !== 'top' || $surface !== 'shadow' || $hover !== 'default' || $customFrame;

        $inner = '';
        if ($image !== '' && $layout !== 'text') {
            $imageAttrs = responsiveImageAttributes(
                $image,
                'medium',
                '(min-width: 1280px) 384px, (min-width: 768px) 50vw, 100vw'
            );
            $frameClass = $customFrame ? ' yk-card-media-framed' . ($ratio === 'auto' ? ' yk-card-media-original' : '') : '';
            $frameStyle = $customFrame ? ' style="aspect-ratio:' . (BloxImageFraming::ratioCss($ratio) ?: 'auto') . ';"' : '';
            $objectStyle = BloxImageFraming::objectStyle($data);
            $imageStyle = $ratio !== 'auto' && $objectStyle !== BloxImageFraming::objectStyle([]) ? ' style="' . $objectStyle . '"' : '';
            $inner .= '<div class="aspect-video overflow-hidden bg-gray-100' . ($enhanced ? ' yk-card-media' : '') . $frameClass . '"' . $frameStyle . '><img ' . $imageAttrs
                . ' alt="" loading="lazy" decoding="async" class="w-full h-full object-cover"' . $imageStyle . '></div>';
        }
        $body = '';
        if ($title !== '') {
            $body .= '<h3 class="text-lg font-semibold mb-2">' . $title . '</h3>';
        }
        if ($text !== '') {
            $body .= $richDescription
                ? '<div class="text-sm text-gray-500 yk-description">' . $text . '</div>'
                : '<p class="text-sm text-gray-500">' . $text . '</p>';
        }
        if ($body !== '') {
            $inner .= '<div class="p-4' . ($enhanced ? ' yk-card-body' : '') . '">' . $body . '</div>';
        }

        $cls = match ($surface) {
            'plain' => 'block bg-white rounded-lg overflow-hidden',
            'border' => 'block bg-white rounded-lg border border-gray-200 overflow-hidden',
            default => 'block bg-white rounded-lg border border-gray-100 shadow-sm overflow-hidden',
        };
        if ($enhanced) {
            $cls .= ' yk-card';
            $side = $layout === 'side' && $image !== '' && $body !== '' ? ' yk-card-side' : '';
            $inner = '<div class="yk-card-content' . $side . '">' . $inner . '</div>';
        }
        $animationAttrs = $this->animationAttrs($data);
        if ($link !== '') {
            $hoverClass = match ($hover) {
                'lift' => ' yk-card-hover-lift',
                'zoom' => $image !== '' && $layout !== 'text' ? ' yk-card-hover-zoom' : '',
                'none' => '',
                default => ' hover:shadow-md transition',
            };
            return '<a href="' . $link . '" class="' . $cls . $hoverClass . ' no-underline"' . $animationAttrs . '>' . $inner . '</a>';
        }
        return '<div class="' . $cls . '"' . $animationAttrs . '>' . $inner . '</div>';
    }
}
