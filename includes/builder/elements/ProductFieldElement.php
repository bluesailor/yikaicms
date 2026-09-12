<?php
/** Dynamic product fields reuse existing elements and their style controls. */
declare(strict_types=1);

final class ProductFieldElement extends AbstractElement
{
    public function __construct(private string $field) {}

    private function delegate(): AbstractElement
    {
        return match ($this->field) {
            'title' => new HeadingElement(),
            'image' => new ImageElement(),
            'button' => new ButtonElement(),
            default => new TextElement(),
        };
    }

    public function type(): string { return 'product-' . $this->field; }
    public function label(): string { return __('blox_product_' . $this->field); }
    public function icon(): string { return $this->delegate()->icon(); }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'product-detail'; }
    public function backgroundRenderStrategy(): string { return $this->delegate()->backgroundRenderStrategy(); }

    public function controls(): array
    {
        $controls = [];
        foreach ($this->delegate()->controls() as $control) {
            $key = $control['key'];
            if (str_starts_with($key, 'site_') || str_starts_with($key, 'loop_')
                || in_array($key, ['html', 'src', 'alt', 'url'], true)
                || ($key === 'text' && $this->field !== 'button')) continue;
            if ($key === 'level') $control['default'] = 'h1';
            if ($key === 'text') $control['default'] = __('blox_product_link_label');
            $controls[] = $control;
        }
        return $controls;
    }

    public function render(array $data, string $children = ''): string
    {
        $product = ProductTemplateDocument::currentProduct();
        if ($product === null) return '';
        // Ignore forged static/binding fields, including those in old imported documents.
        foreach (array_keys($data) as $key) {
            if (str_starts_with((string) $key, 'site_') || str_starts_with((string) $key, 'loop_')) unset($data[$key]);
        }
        unset($data['_responsive_image_field'], $data['_responsive_image_fallback']);
        switch ($this->field) {
            case 'title':
                $data['text'] = (string) ($product['title'] ?? '');
                $data['level'] ??= 'h1';
                break;
            case 'image':
                $data['src'] = (string) ($product['cover'] ?? '');
                $data['alt'] = (string) ($product['title'] ?? '');
                break;
            case 'content':
                $data['html'] = function_exists('sanitizeHtml') ? sanitizeHtml((string) ($product['content'] ?? '')) : '';
                break;
            case 'button':
                $data['text'] ??= __('blox_product_link_label');
                $data['url'] = productPrettyUrl($product);
                break;
        }
        return $this->delegate()->render($data);
    }
}
