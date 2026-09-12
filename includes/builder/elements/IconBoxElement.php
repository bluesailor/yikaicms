<?php
/** 图标框：图标 + 标题 + 描述，居中。schema 驱动（图标用 Tabler 名文本框）。 */

declare(strict_types=1);

final class IconBoxElement extends AbstractElement
{
    public function type(): string { return 'icon-box'; }
    public function label(): string { return __('blox_el_icon_box'); }
    public function icon(): string { return 'box'; }
    /** 通用背景：root——渲染后由 BlockRenderer 注入首标签 */
    public function backgroundRenderStrategy(): string { return 'root'; }

    public function controls(): array
    {
        return [
            // type=icon：编辑器渲染成带图标库选择器的控件（blox 全量库 / 排版编辑器精选集）
            ['key' => 'icon', 'type' => 'icon', 'label' => __('blox_ctl_icon'), 'default' => 'star'],
            ['key' => 'icon_motion', 'type' => 'select', 'label' => __('blox_icon_motion'), 'default' => 'none',
                'options' => BloxIcon::motionOptions()],
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_field_title_short'), 'default' => ''],
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_desc'), 'default' => '', 'rows' => 2,
                'compact_richtext' => true, 'format_key' => 'text_format'],
            ['key' => 'text_format', 'type' => 'select', 'label' => __('blox_desc_format'), 'default' => 'plain',
                'editor_hidden' => true, 'options' => ['plain' => __('blox_desc_plain'), 'html' => 'HTML']],
            ['key' => 'icon_layout', 'type' => 'select', 'label' => __('blox_icon_box_layout'), 'default' => 'top', 'tab' => 'style',
                'option_preview' => 'icon-layout',
                'options' => ['top' => __('blox_icon_box_top'), 'side' => __('blox_icon_box_side'), 'compact' => __('blox_icon_box_compact')]],
            ['key' => 'icon_surface', 'type' => 'select', 'label' => __('blox_icon_box_surface'), 'default' => 'none', 'tab' => 'style',
                'option_preview' => 'icon-surface',
                'options' => ['none' => __('blox_icon_box_none'), 'circle' => __('blox_icon_box_circle'), 'square' => __('blox_icon_box_square')]],
            ...$this->backgroundControls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $icon = $data['icon'] ?? 'star';
        $title = htmlspecialchars($data['title'] ?? '');
        $richDescription = ($data['text_format'] ?? null) === 'html';
        $rawText = is_scalar($data['text'] ?? null) ? (string) $data['text'] : '';
        $text = $richDescription ? HtmlPolicy::description($rawText) : htmlspecialchars($rawText);
        $layout = in_array($data['icon_layout'] ?? null, ['top', 'side', 'compact'], true) ? $data['icon_layout'] : 'top';
        $surface = in_array($data['icon_surface'] ?? null, ['none', 'circle', 'square'], true) ? $data['icon_surface'] : 'none';
        $enhanced = $layout !== 'top' || $surface !== 'none';
        $cls = $enhanced ? 'yk-icon-box yk-icon-box-' . $layout : 'text-center px-4 py-6';
        $html = '<div class="yk-icon-interactive ' . $cls . '"' . $this->animationAttrs($data) . '>';
        if ($enhanced) {
            $html .= '<span class="yk-icon-box-symbol yk-icon-box-symbol-' . $surface . '" aria-hidden="true">';
        }
        $size = $layout === 'compact' ? '24' : '40';
        $html .= '<i aria-hidden="true" class="' . BloxIcon::classes($icon) . ' inline-block text-primary' . BloxIcon::motionClass($data['icon_motion'] ?? '') . '" style="font-size:' . $size . 'px;line-height:1"></i>';
        if ($enhanced) {
            $html .= '</span><div class="yk-icon-box-copy">';
        }
        if ($title !== '') {
            $html .= '<h3 class="text-lg font-semibold mt-3 mb-1">' . $title . '</h3>';
        }
        if ($text !== '') {
            $html .= $richDescription
                ? '<div class="text-sm text-gray-500 yk-description">' . $text . '</div>'
                : '<p class="text-sm text-gray-500">' . $text . '</p>';
        }
        return $html . ($enhanced ? '</div>' : '') . '</div>';
    }

    public function stylesFor(array $data): array
    {
        $stylesheet = BloxIcon::stylesheet($data['icon'] ?? null);
        return $stylesheet === null ? [] : [$stylesheet];
    }
}
