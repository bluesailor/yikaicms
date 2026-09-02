<?php
/** 引用元素：引文 + 署名。schema 驱动。 */

declare(strict_types=1);

final class QuoteElement extends AbstractElement
{
    public function type(): string { return 'quote'; }
    public function label(): string { return __('blox_el_quote'); }
    public function icon(): string { return 'quote'; }
    /** 通用背景：root——渲染后由 BlockRenderer 注入 blockquote 首标签 */
    public function backgroundRenderStrategy(): string { return 'root'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_quote_text'), 'default' => '', 'rows' => 3],
            ['key' => 'author', 'type' => 'text', 'label' => __('blox_quote_author'), 'default' => ''],
            ...$this->backgroundControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $text = htmlspecialchars($data['text'] ?? '');
        $author = htmlspecialchars($data['author'] ?? '');
        $html = '<blockquote class="border-l-4 border-primary pl-4 py-2 my-4 italic text-gray-600">' . $text;
        if ($author !== '') {
            $html .= '<footer class="mt-2 text-sm not-italic text-gray-400">— ' . $author . '</footer>';
        }
        return $html . '</blockquote>';
    }
}
