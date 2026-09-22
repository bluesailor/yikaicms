<?php
/**
 * 商城购买入口渲染（原生产品页与 Blox 商品详情模板共用）。
 *
 * 预览态只输出禁用控件，不带 action/签名；正式态才生成 shop_cart 令牌。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/cart.php';

/**
 * @param array<string,mixed> $product 产品语言行；id 用于提交，translation_group_id 用于共享库存查询
 * @param array{preview?:bool,show_price?:bool,show_stock?:bool,layout?:string,radius?:string,native_margin?:bool} $options
 */
function shopRenderPurchaseForm(array $product, array $options = []): string
{
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0) {
        return '';
    }

    $preview = !empty($options['preview']);
    try {
        $sales = shopCartSalesLookupDefault(shopCanonicalProductId($product));
    } catch (Throwable $e) {
        return $preview ? shopRenderPurchaseUnavailablePreview() : '';
    }
    if ($sales === null || (int) ($sales['stock'] ?? 0) <= 0) {
        return $preview ? shopRenderPurchaseUnavailablePreview() : '';
    }
    $variants = shopProductVariantsFromJson(isset($sales['specs_json']) ? (string) $sales['specs_json'] : null);
    $availableVariants = array_values(array_filter(
        $variants,
        static fn(array $variant): bool => (int) ($variant['stock'] ?? 0) > 0
    ));
    if ($variants !== [] && $availableVariants === []) {
        return $preview ? shopRenderPurchaseUnavailablePreview() : '';
    }
    $selectedVariant = $availableVariants[0] ?? null;
    $pricedSales = $sales;
    if (is_array($selectedVariant)) {
        $pricedSales['sale_price'] = $selectedVariant['price'] ?? ($sales['sale_price'] ?? null);
        $pricedSales['stock'] = (int) $selectedVariant['stock'];
    }

    $showPrice = !array_key_exists('show_price', $options) || !empty($options['show_price']);
    $showStock = !array_key_exists('show_stock', $options) || !empty($options['show_stock']);
    $layout = ($options['layout'] ?? 'stacked') === 'inline' ? 'inline' : 'stacked';
    $radius = in_array($options['radius'] ?? '', ['none', 'md', 'xl'], true)
        ? (string) $options['radius']
        : 'none';
    $radiusClass = ['none' => '', 'md' => ' rounded-lg', 'xl' => ' rounded-2xl'][$radius];
    $marginClass = !empty($options['native_margin']) ? ' mt-4' : '';
    $formClass = $layout === 'inline'
        ? 'flex flex-wrap items-center gap-2'
        : 'flex flex-col items-stretch gap-3';

    $priceHtml = '';
    if ($showPrice) {
        try {
            $decimal = shopCentsToDecimal(shopEffectivePriceCents($product, $pricedSales));
            // 独立领域测试不会加载整套 functions.php；正式运行时始终走站点货币格式。
            $price = function_exists('formatPrice') ? formatPrice($decimal) : e($decimal);
            $priceHtml = '<p class="mb-3 text-2xl font-semibold text-primary" data-shop-price data-testid="shop-buy-price">'
                . $price . '</p>';
        } catch (InvalidArgumentException|TypeError $e) {
            // 销售数据异常时不猜价格；加购/结算仍会在各自边界重新校验。
            $priceHtml = '';
        }
    }

    $stock = (int) $pricedSales['stock'];
    $maxQty = min(999, $stock);
    $variantField = '';
    if ($availableVariants !== []) {
        $variantField = '<label class="block min-w-48">'
            . '<span class="block text-xs text-gray-500 mb-1">' . e(__('shop_variant_select')) . '</span>'
            . '<select name="variant" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-shop-variant data-testid="shop-buy-variant">';
        foreach ($availableVariants as $variant) {
            $variantSales = $sales;
            $variantSales['sale_price'] = $variant['price'] ?? ($sales['sale_price'] ?? null);
            $variantPrice = shopCentsToDecimal(shopEffectivePriceCents($product, $variantSales));
            $formattedPrice = function_exists('formatPrice') ? formatPrice($variantPrice) : $variantPrice;
            $optionText = $variant['label']
                . ($variant['sku'] !== '' ? ' · ' . $variant['sku'] : '')
                . ' · ' . $formattedPrice
                . ' · ' . __('shop_stock_left', ['n' => (string) $variant['stock']]);
            $variantField .= '<option value="' . e($variant['id']) . '" data-price="' . e($formattedPrice)
                . '" data-stock="' . (int) $variant['stock'] . '">' . e($optionText) . '</option>';
        }
        $variantField .= '</select></label>';
    }
    $fields = $variantField . '<label class="block">'
        . '<span class="sr-only">' . e(__('shop_col_qty')) . '</span>'
        . '<input type="number" name="qty" min="1" max="' . $maxQty . '" step="1" value="1"'
        . ' class="border border-gray-300 rounded px-3 py-2 text-sm w-20" data-testid="shop-buy-qty">'
        . '</label>'
        . '<button type="submit" class="bg-primary hover:bg-secondary text-white text-sm px-5 py-2 rounded"'
        . ($preview ? ' disabled' : '') . ' data-testid="shop-buy-submit">'
        . e(__('shop_btn_add_cart')) . '</button>'
        . ($showStock
            ? '<span class="text-xs text-gray-400" data-shop-stock>'
                . e(__('shop_stock_left', ['n' => (string) $stock])) . '</span>'
            : '');

    if ($preview) {
        $form = '<form class="' . $formClass . '" data-yk-preview="1">'
            . '<fieldset disabled class="contents">' . $fields . '</fieldset>'
            . '</form>';
    } else {
        $secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
        if ($secret === '') {
            return '';
        }
        $timestamp = time();
        $signature = FormSubmissionToken::sign('shop_cart', $timestamp, $secret);
        $form = '<form method="post" action="/shop/api" class="' . $formClass . '">'
            . '<input type="hidden" name="op" value="add">'
            . '<input type="hidden" name="pid" value="' . $productId . '">'
            . '<input type="hidden" name="ts" value="' . $timestamp . '">'
            . '<input type="hidden" name="sig" value="' . e($signature) . '">'
            . $fields
            . '</form>';
    }

    $script = $availableVariants === [] ? '' : '<script>(function(){var r=document.currentScript.parentElement,s=r.querySelector("[data-shop-variant]"),q=r.querySelector("[name=qty]"),p=r.querySelector("[data-shop-price]"),t=r.querySelector("[data-shop-stock]");if(!s)return;function u(){var o=s.options[s.selectedIndex],n=o.dataset.stock||"0";q.max=Math.min(999,parseInt(n,10)||0);if(parseInt(q.value,10)>parseInt(q.max,10))q.value=q.max;if(p)p.textContent=o.dataset.price||"";if(t)t.textContent=' . json_encode(__('shop_stock_left', ['n' => '__N__']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . '.replace("__N__",n)}s.addEventListener("change",u);u()})()</script>';

    return '<div class="yk-shop-purchase' . $marginClass . $radiusClass
        . '" data-testid="shop-buy-form">' . $priceHtml . $form . $script . '</div>';
}

/** 仅给 Blox 画布看的缺货/未上架占位；正式前台保持原有静默不渲染。 */
function shopRenderPurchaseUnavailablePreview(): string
{
    return '<div class="rounded-lg border border-dashed border-amber-300 bg-amber-50 p-4 text-sm text-amber-700"'
        . ' data-testid="shop-buy-preview-unavailable">'
        . e(__('shop_blox_unavailable_preview')) . '</div>';
}
