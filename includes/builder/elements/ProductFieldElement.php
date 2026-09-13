<?php
/** Dynamic product fields reuse existing elements and their style controls. */
declare(strict_types=1);

final class ProductFieldElement extends AbstractElement
{
    /** 允许的字段。前 4 个为核心数据字段，后 4 个为派生业务字段（相册/参数/上下篇/相关）。 */
    private const LABEL_KEYS = [
        'title' => 'blox_product_title',
        'image' => 'blox_product_image',
        'content' => 'blox_product_content',
        'button' => 'blox_product_button',
        'gallery' => 'blox_product_gallery',
        'specs' => 'blox_product_specs',
        'prev-next' => 'blox_product_prev_next',
        'related' => 'blox_product_related',
    ];

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

    public function label(): string
    {
        return __(self::LABEL_KEYS[$this->field] ?? 'blox_product_content');
    }
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
        $context = ProductTemplateDocument::currentProduct() ?? [];
        $title = (string) ($context['title'] ?? '');

        switch ($this->field) {
            case 'gallery':
                // 相册：只列控制器解析出的真实图片组；没有图不渲染占位（与原生「无图占位」区分：
                // 动态元素缺失时整块隐藏，由模板作者决定要不要放占位元素）
                $images = is_array($context['images'] ?? null) ? $context['images'] : [];
                if ($images === []) return '';
                $items = [];
                foreach ($images as $image) {
                    if (!is_string($image) || $image === '') continue;
                    $items[] = '<img alt="' . e($title) . '" loading="lazy" decoding="async" '
                        . responsiveImageAttributes($image, 'medium', '(min-width: 1024px) 25vw, 50vw')
                        . ' class="w-full h-full object-cover">';
                }
                if ($items === []) return '';
                $data['html'] = '<div class="yk-product-gallery grid grid-cols-2 md:grid-cols-3 gap-3">'
                    . implode('', $items) . '</div>';
                break;

            case 'specs':
                $specs = is_array($context['specs'] ?? null) ? $context['specs'] : [];
                $rows = [];
                foreach ($specs as $spec) {
                    if (!is_array($spec)) continue;
                    $rows[] = '<tr><td class="py-2 w-1/4 text-gray-500 font-medium">' . e((string) ($spec['name'] ?? ''))
                        . '</td><td class="py-2">' . e((string) ($spec['value'] ?? '')) . '</td></tr>';
                }
                if ($rows === []) return '';   // 无参数不上空表
                $data['html'] = '<table class="w-full"><tbody class="divide-y">' . implode('', $rows) . '</tbody></table>';
                break;

            case 'prev-next':
                $links = [];
                $prev = is_array($context['prev'] ?? null) ? $context['prev'] : null;
                $next = is_array($context['next'] ?? null) ? $context['next'] : null;
                if ($prev !== null && trim((string) ($prev['title'] ?? '')) !== '') {
                    $links[] = '<a href="' . e(productUrl($prev)) . '">' . e(__('detail_prev_product')) . '：'
                        . e((string) $prev['title']) . '</a>';
                }
                if ($next !== null && trim((string) ($next['title'] ?? '')) !== '') {
                    $links[] = '<a href="' . e(productUrl($next)) . '">' . e(__('detail_next_product')) . '：'
                        . e((string) $next['title']) . '</a>';
                }
                if ($links === []) return '';
                $data['html'] = '<nav class="yk-product-prev-next">' . implode('', $links) . '</nav>';
                break;

            case 'related':
                $related = is_array($context['related'] ?? null) ? $context['related'] : [];
                $items = [];
                foreach ($related as $row) {
                    if (!is_array($row)) continue;
                    $label = trim((string) ($row['title'] ?? ''));
                    if ($label === '') continue;
                    $items[] = '<li><a href="' . e(productUrl($row)) . '">' . e($label) . '</a></li>';
                }
                if ($items === []) return '';
                $data['html'] = '<div class="yk-product-related"><h2>' . e(__('detail_related_products'))
                    . '</h2><ul>' . implode('', $items) . '</ul></div>';
                break;

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
