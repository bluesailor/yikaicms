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
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_quote_text'), 'default' => '', 'rows' => 3,
                'compact_richtext' => true, 'format_key' => 'text_format'],
            ['key' => 'text_format', 'type' => 'select', 'label' => __('blox_desc_format'), 'default' => 'plain',
                'editor_hidden' => true, 'options' => ['plain' => __('blox_desc_plain'), 'html' => 'HTML']],
            ['key' => 'author', 'type' => 'text', 'label' => __('blox_quote_author'), 'default' => ''],
            ['key' => 'quote_style', 'type' => 'select', 'label' => __('blox_quote_appearance'), 'default' => 'classic', 'tab' => 'style',
                'option_preview' => 'quote-style', 'options' => ['classic' => __('blox_quote_classic'), 'soft' => __('blox_surface_soft'), 'center' => __('blox_quote_center')]],
            ...$this->backgroundControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $rich = ($data['text_format'] ?? null) === 'html';
        $raw = is_scalar($data['text'] ?? null) ? (string) $data['text'] : '';
        $text = $rich ? '<div class="yk-description">' . HtmlPolicy::description($raw) . '</div>' : htmlspecialchars($raw);
        $author = htmlspecialchars(is_scalar($data['author'] ?? null) ? (string) $data['author'] : '');
        $surface = in_array($data['quote_style'] ?? null, ['classic', 'soft', 'center'], true) ? $data['quote_style'] : 'classic';
        $cls = $surface === 'classic' ? 'border-l-4 border-primary pl-4 py-2 my-4 italic text-gray-600' : 'yk-quote yk-quote-' . $surface;
        $html = '<blockquote class="' . $cls . '">';
        if ($surface === 'center') {
            $html .= '<i class="' . BloxIcon::classes('quote') . ' yk-quote-icon" aria-hidden="true"></i>';
        }
        $html .= $text;
        if ($author !== '') {
            $html .= '<footer class="mt-2 text-sm not-italic text-gray-400">— ' . $author . '</footer>';
        }
        return $html . '</blockquote>';
    }
}
