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
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_desc'), 'default' => '', 'rows' => 2],
            ...$this->backgroundControls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $icon = $data['icon'] ?? 'star';
        $title = htmlspecialchars($data['title'] ?? '');
        $text = htmlspecialchars($data['text'] ?? '');
        $html = '<div class="yk-icon-interactive text-center px-4 py-6"' . $this->animationAttrs($data) . '>';
        $html .= '<i aria-hidden="true" class="' . BloxIcon::classes($icon) . ' inline-block text-primary' . BloxIcon::motionClass($data['icon_motion'] ?? '') . '" style="font-size:40px;line-height:1"></i>';
        if ($title !== '') {
            $html .= '<h3 class="text-lg font-semibold mt-3 mb-1">' . $title . '</h3>';
        }
        if ($text !== '') {
            $html .= '<p class="text-sm text-gray-500">' . $text . '</p>';
        }
        return $html . '</div>';
    }

    public function stylesFor(array $data): array
    {
        $stylesheet = BloxIcon::stylesheet($data['icon'] ?? null);
        return $stylesheet === null ? [] : [$stylesheet];
    }
}
