<?php
/** 卡片元素：图 + 标题 + 文 + 可选链接。schema 驱动（图用 URL 文本框）。 */

declare(strict_types=1);

final class CardElement extends AbstractElement
{
    public function type(): string { return 'card'; }
    public function label(): string { return '卡片'; }
    public function icon(): string { return 'square-rounded'; }
    public function category(): string { return 'media'; }

    public function controls(): array
    {
        return [
            ['key' => 'image', 'type' => 'text', 'label' => '图片 URL', 'default' => ''],
            ['key' => 'title', 'type' => 'text', 'label' => '标题', 'default' => ''],
            ['key' => 'text', 'type' => 'textarea', 'label' => '描述', 'default' => '', 'rows' => 2],
            ['key' => 'link', 'type' => 'text', 'label' => '链接', 'default' => '', 'placeholder' => '留空=不可点'],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $image = htmlspecialchars($data['image'] ?? '');
        $title = htmlspecialchars($data['title'] ?? '');
        $text = htmlspecialchars($data['text'] ?? '');
        $link = htmlspecialchars($data['link'] ?? '');

        $inner = '';
        if ($image !== '') {
            $inner .= '<div class="aspect-video overflow-hidden bg-gray-100"><img src="' . $image . '" alt="" loading="lazy" class="w-full h-full object-cover"></div>';
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
        if ($link !== '') {
            return '<a href="' . $link . '" class="' . $cls . ' hover:shadow-md transition no-underline">' . $inner . '</a>';
        }
        return '<div class="' . $cls . '">' . $inner . '</div>';
    }
}
