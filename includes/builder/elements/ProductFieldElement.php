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
        return self::withoutOrphanedRules($controls);
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
                    // 锚点带原图 href：PhotoSwipe 接管时用它做大图，脚本缺失时退化为普通链接
                    $items[] = '<a href="' . e($image) . '" data-yk-gallery-item'
                        . ' data-yk-gallery-full="' . e($image) . '"'
                        . ' data-yk-gallery-alt="' . e($title) . '"'
                        . ' aria-label="' . e(__('blox_product_gallery_zoom')) . '"'
                        . ' class="block aspect-square overflow-hidden rounded-lg bg-gray-100">'
                        . '<img alt="' . e($title) . '" loading="lazy" decoding="async" '
                        . responsiveImageAttributes($image, 'medium', '(min-width: 1024px) 25vw, 50vw')
                        . ' class="w-full h-full object-cover"></a>';
                }
                if ($items === []) return '';
                $data['html'] = '<div class="yk-product-gallery grid grid-cols-2 md:grid-cols-3 gap-3" data-yk-gallery>'
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
        // 派生业务字段的标记是引擎自己拼的（每个值都过 e()），不是用户富文本：
        // 不能走 TextElement 的 sanitizeHtml——净化会把 data-yk-gallery-* 这类交互属性
        // 连同灯箱锚点一起剥掉，相册就点不开了。内容字段仍走 delegate 保持既有净化。
        if (in_array($this->field, ['gallery', 'specs', 'prev-next', 'related'], true)) {
            return $this->textShell($data, (string) ($data['html'] ?? ''));
        }
        return $this->delegate()->render($data);
    }

    /** 与 TextElement 同款外观壳（prose + 圆角 + 颜色 + 动画），区别是不做富文本净化。 */
    private function textShell(array $data, string $html): string
    {
        $radiusKey = is_string($data['radius'] ?? null) ? $data['radius'] : 'none';
        $radius = ['none' => '', 'md' => ' rounded-lg', 'xl' => ' rounded-2xl'][$radiusKey] ?? '';
        $color = self::cssColor($data['color'] ?? null);
        $style = $color !== null ? ' style="color:' . htmlspecialchars($color, ENT_QUOTES) . ';"' : '';

        return '<div class="prose prose-lg max-w-none' . $radius . '"' . $style
            . $this->animationAttrs($data) . '>' . $html . '</div>';
    }

    /**
     * 相册灯箱依赖：站点既有 PhotoSwipe 资源 + 绑定脚本。
     *
     * 走 BloxAssetCollector（元素声明 → 页面按实际渲染节点去重输出），
     * 不在这里手写灯箱：放大/滑动/键盘/关闭动画都是 PhotoSwipe 的行为，
     * 与原生 product.php 共用同一批本地资源（无 CDN）。
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        if ($this->field !== 'gallery') return [];
        return [
            '/assets/photoswipe/photoswipe.umd.min.js',
            '/assets/photoswipe/photoswipe-lightbox.umd.min.js',
            '/assets/js/blox-product-gallery.js',
        ];
    }

    /** @return list<string> */
    public function styles(): array
    {
        return $this->field === 'gallery' ? ['/assets/photoswipe/photoswipe.css'] : [];
    }
}
