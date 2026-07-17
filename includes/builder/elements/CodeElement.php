<?php
/** 代码/HTML 元素。对齐旧 case 'code'（原样输出，含短码/iframe）。 */

declare(strict_types=1);

final class CodeElement extends AbstractElement
{
    public function type(): string { return 'code'; }
    public function label(): string { return '代码/HTML'; }
    public function icon(): string { return 'code'; }
    public function category(): string { return 'advanced'; }

    public function controls(): array
    {
        return [
            ['key' => 'html', 'type' => 'textarea', 'label' => 'HTML/短码', 'default' => '',
                'placeholder' => '输入 HTML、短码 [form-xxx]、iframe 等...', 'rows' => 4],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        return $data['html'] ?? '';
    }
}
