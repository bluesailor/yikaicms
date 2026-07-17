<?php
/** 图片元素。对齐旧 case 'image'（含 lightbox / link 点击行为；无 src 返回空）。 */

declare(strict_types=1);

final class ImageElement extends AbstractElement
{
    public function type(): string { return 'image'; }
    public function label(): string { return '图片'; }
    public function icon(): string { return 'photo'; }
    public function category(): string { return 'media'; }

    // image 由构建器选图/上传组件接管（hasCustomUI），此处仅供默认值 / 元数据
    public function controls(): array
    {
        return [
            ['key' => 'src', 'type' => 'image', 'label' => '图片', 'default' => ''],
            ['key' => 'alt', 'type' => 'text', 'label' => '描述', 'default' => ''],
            ['key' => 'click_action', 'type' => 'select', 'label' => '点击', 'default' => '',
                'options' => ['' => '无动作', 'lightbox' => '弹出大图', 'link' => '跳转链接']],
            ['key' => 'link_url', 'type' => 'text', 'label' => '链接', 'default' => ''],
            ['key' => 'link_new_tab', 'type' => 'checkbox', 'label' => '新窗口', 'default' => false],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $src = htmlspecialchars($data['src'] ?? '');
        $alt = htmlspecialchars($data['alt'] ?? '');
        if (!$src) {
            return '';
        }
        $clickAction = $data['click_action'] ?? '';
        $imgTag = '<img class="w-full rounded-lg" src="' . $src . '" alt="' . $alt . '" loading="lazy">';
        if ($clickAction === 'lightbox') {
            return '<a href="' . $src . '" data-lightbox class="block cursor-zoom-in">' . $imgTag . '</a>';
        }
        if ($clickAction === 'link' && !empty($data['link_url'])) {
            $linkUrl = htmlspecialchars($data['link_url']);
            $target = !empty($data['link_new_tab']) ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . $linkUrl . '"' . $target . ' class="block">' . $imgTag . '</a>';
        }
        return $imgTag;
    }
}
