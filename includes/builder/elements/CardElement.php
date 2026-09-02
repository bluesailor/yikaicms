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
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_desc'), 'default' => '', 'rows' => 2],
            ['key' => 'link', 'type' => 'url', 'label' => __('blox_ctl_link'), 'default' => '', 'placeholder' => __('blox_empty_unclickable')],
            ...$this->backgroundControls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $image = UrlPolicy::storedImage($data['image'] ?? '');
        $title = htmlspecialchars($data['title'] ?? '');
        $text = htmlspecialchars($data['text'] ?? '');
        // javascript: 等伪协议在这里拦；非法地址视同未填——退化为不可点击的 div
        $link = htmlspecialchars(self::safeHref($data['link'] ?? ''));

        $inner = '';
        if ($image !== '') {
            $imageAttrs = responsiveImageAttributes(
                $image,
                'medium',
                '(min-width: 1280px) 384px, (min-width: 768px) 50vw, 100vw'
            );
            $inner .= '<div class="aspect-video overflow-hidden bg-gray-100"><img ' . $imageAttrs
                . ' alt="" loading="lazy" decoding="async" class="w-full h-full object-cover"></div>';
        }
        $body = '';
        if ($title !== '') {
            $body .= '<h3 class="text-lg font-semibold mb-2">' . $title . '</h3>';
        }
        if ($text !== '') {
            $body .= '<p class="text-sm text-gray-500">' . $text . '</p>';
        }
        if ($body !== '') {
            $inner .= '<div class="p-4">' . $body . '</div>';
        }

        $cls = 'block bg-white rounded-lg border border-gray-100 shadow-sm overflow-hidden';
        $animationAttrs = $this->animationAttrs($data);
        if ($link !== '') {
            return '<a href="' . $link . '" class="' . $cls . ' hover:shadow-md transition no-underline"' . $animationAttrs . '>' . $inner . '</a>';
        }
        return '<div class="' . $cls . '"' . $animationAttrs . '>' . $inner . '</div>';
    }
}
