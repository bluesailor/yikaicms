<?php
/** 引用元素：引文 + 署名。schema 驱动。 */

declare(strict_types=1);

final class QuoteElement extends AbstractElement
{
    public function type(): string { return 'quote'; }
    public function label(): string { return '引用'; }
    public function icon(): string { return 'quote'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'textarea', 'label' => '引文', 'default' => '', 'rows' => 3],
            ['key' => 'author', 'type' => 'text', 'label' => '署名', 'default' => ''],
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
