<?php
/** 文本（富文本）元素。对齐旧 case 'text'。 */

declare(strict_types=1);

final class TextElement extends AbstractElement
{
    public function type(): string { return 'text'; }
    public function label(): string { return '文本'; }
    public function icon(): string { return 'align-left'; }

    public function render(array $data, string $children = ''): string
    {
        return '<div class="prose prose-lg max-w-none">' . ($data['html'] ?? '') . '</div>';
    }
}
