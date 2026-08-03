<?php
/** 图标框：图标 + 标题 + 描述，居中。schema 驱动（图标用 Tabler 名文本框）。 */

declare(strict_types=1);

final class IconBoxElement extends AbstractElement
{
    public function type(): string { return 'icon-box'; }
    public function label(): string { return '图标框'; }
    public function icon(): string { return 'box'; }

    public function controls(): array
    {
        return [
            // type=icon：编辑器渲染成带图标库选择器的控件（blox 全量库 / 排版编辑器精选集）
            ['key' => 'icon', 'type' => 'icon', 'label' => '图标', 'default' => 'star'],
            ['key' => 'title', 'type' => 'text', 'label' => '标题', 'default' => ''],
            ['key' => 'text', 'type' => 'textarea', 'label' => '描述', 'default' => '', 'rows' => 2],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $icon = preg_replace('/[^a-z0-9-]/', '', (string) ($data['icon'] ?? 'star')) ?: 'star';
        $title = htmlspecialchars($data['title'] ?? '');
        $text = htmlspecialchars($data['text'] ?? '');
        $html = '<div class="text-center px-4 py-6"' . $this->animationAttrs($data) . '>';
        $html .= '<i class="ti ti-' . $icon . ' inline-block text-primary" style="font-size:40px;line-height:1"></i>';
        if ($title !== '') {
            $html .= '<h3 class="text-lg font-semibold mt-3 mb-1">' . $title . '</h3>';
        }
        if ($text !== '') {
            $html .= '<p class="text-sm text-gray-500">' . $text . '</p>';
        }
        return $html . '</div>';
    }
}
