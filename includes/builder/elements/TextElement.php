<?php
/** 文本（富文本）元素。对齐旧 case 'text'。 */

declare(strict_types=1);

final class TextElement extends AbstractElement
{
    public function type(): string { return 'text'; }
    public function label(): string { return '文本'; }
    public function icon(): string { return 'align-left'; }

    // richtext 由构建器 wangEditor 弹窗接管（hasCustomUI），此处仅供默认值 / 元数据
    public function controls(): array
    {
        return [['key' => 'html', 'type' => 'richtext', 'label' => '正文', 'default' => '']];
    }

    public function render(array $data, string $children = ''): string
    {
        return '<div class="prose prose-lg max-w-none">' . ($data['html'] ?? '') . '</div>';
    }
}
