<?php
/** 代码/HTML 元素。对齐旧 case 'code'（原样输出，含短码/iframe）。 */

declare(strict_types=1);

final class CodeElement extends AbstractElement
{
    public function type(): string { return 'code'; }
    public function label(): string { return __('blox_el_code'); }
    public function icon(): string { return 'code'; }
    public function category(): string { return 'advanced'; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'html', 'type' => 'textarea', 'label' => __('blox_html_shortcode'), 'default' => '',
                'placeholder' => __('blox_html_ph'), 'rows' => 4],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        return $data['html'] ?? '';
    }

    public function renderWithContext(array $data, string $children = '', array $context = []): string
    {
        $html = $this->render($data, $children);
        if (empty($context['edit_mode'])) {
            return $html;
        }

        // The canvas runs trusted runtime scripts under a CSP nonce. User-authored
        // Code element scripts must not inherit that nonce in an authenticated frame.
        return (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    }
}
