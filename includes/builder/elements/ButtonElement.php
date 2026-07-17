<?php
/** 按钮元素。对齐旧 case 'button'。 */

declare(strict_types=1);

final class ButtonElement extends AbstractElement
{
    public function type(): string { return 'button'; }
    public function label(): string { return '按钮'; }
    public function icon(): string { return 'square-rounded'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => '按钮文字', 'default' => '按钮'],
            ['key' => 'url', 'type' => 'text', 'label' => '链接地址', 'default' => '', 'placeholder' => 'https:// 或 /path'],
            ['key' => 'new_tab', 'type' => 'checkbox', 'label' => '新窗口打开', 'default' => false],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $text = htmlspecialchars($data['text'] ?? '');
        $url = htmlspecialchars($data['url'] ?? '#');
        $target = !empty($data['new_tab']) ? ' target="_blank" rel="noopener"' : '';
        return '<div class="mt-2"><a class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg transition no-underline" style="color:#fff;text-decoration:none" href="' . $url . '"' . $target . '>' . $text . '</a></div>';
    }
}
