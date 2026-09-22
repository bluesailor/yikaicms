<?php
/** Blox 商品详情模板：价格、库存、数量与加入购物车。 */

declare(strict_types=1);

require_once __DIR__ . '/lib/purchase.php';

final class ShopPurchaseElement extends AbstractElement
{
    public function type(): string { return 'shop/purchase'; }
    public function label(): string { return __('shop_blox_purchase'); }
    public function icon(): string { return 'shopping-cart-plus'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'product-detail'; }

    public function controls(): array
    {
        return [
            ['key' => 'show_price', 'type' => 'checkbox', 'label' => __('shop_blox_show_price'), 'default' => true],
            ['key' => 'show_stock', 'type' => 'checkbox', 'label' => __('shop_blox_show_stock'), 'default' => true],
            ['key' => 'layout', 'type' => 'select', 'label' => __('shop_blox_layout'), 'default' => 'stacked',
                'options' => ['stacked' => __('shop_blox_layout_stacked'), 'inline' => __('shop_blox_layout_inline')]],
            ['key' => 'radius', 'type' => 'select', 'label' => __('blox_radius'), 'default' => 'md', 'tab' => 'style',
                'options' => ['none' => __('blox_spacing_none'), 'md' => __('blox_spacing_md'), 'xl' => __('blox_spacing_lg')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $product = ProductTemplateDocument::currentProduct();
        if ($product === null) {
            return '';
        }

        return shopRenderPurchaseForm($product, [
            'preview' => ProductTemplateDocument::isPreview(),
            'show_price' => $data['show_price'] ?? true,
            'show_stock' => $data['show_stock'] ?? true,
            'layout' => is_string($data['layout'] ?? null) ? $data['layout'] : 'stacked',
            'radius' => is_string($data['radius'] ?? null) ? $data['radius'] : 'md',
        ]);
    }
}
