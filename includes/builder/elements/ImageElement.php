<?php
/** 图片元素。对齐旧 case 'image'（含 lightbox / link 点击行为；无 src 返回空）。 */

declare(strict_types=1);

final class ImageElement extends AbstractElement
{
    public function type(): string { return 'image'; }
    public function label(): string { return '图片'; }
    public function icon(): string { return 'photo'; }
    public function category(): string { return 'media'; }

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
