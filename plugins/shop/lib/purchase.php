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
            $decimal = shopCentsToDecimal(shopEffectivePriceCents($product, $sales));
            // 独立领域测试不会加载整套 functions.php；正式运行时始终走站点货币格式。
            $price = function_exists('formatPrice') ? formatPrice($decimal) : e($decimal);
            $priceHtml = '<p class="mb-3 text-2xl font-semibold text-primary" data-testid="shop-buy-price">'
                . $price . '</p>';
        } catch (InvalidArgumentException|TypeError $e) {
            // 销售数据异常时不猜价格；加购/结算仍会在各自边界重新校验。
            $priceHtml = '';
        }
    }

    $stock = (int) $sales['stock'];
    $maxQty = min(999, $stock);
    $fields = '<label class="block">'
        . '<span class="sr-only">' . e(__('shop_col_qty')) . '</span>'
        . '<input type="number" name="qty" min="1" max="' . $maxQty . '" step="1" value="1"'
        . ' class="border border-gray-300 rounded px-3 py-2 text-sm w-20" data-testid="shop-buy-qty">'
        . '</label>'
        . '<button type="submit" class="bg-primary hover:bg-secondary text-white text-sm px-5 py-2 rounded"'
        . ($preview ? ' disabled' : '') . ' data-testid="shop-buy-submit">'
        . e(__('shop_btn_add_cart')) . '</button>'
        . ($showStock
            ? '<span class="text-xs text-gray-400">'
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

    return '<div class="yk-shop-purchase' . $marginClass . $radiusClass
        . '" data-testid="shop-buy-form">' . $priceHtml . $form . '</div>';
}

/** 仅给 Blox 画布看的缺货/未上架占位；正式前台保持原有静默不渲染。 */
function shopRenderPurchaseUnavailablePreview(): string
{
    return '<div class="rounded-lg border border-dashed border-amber-300 bg-amber-50 p-4 text-sm text-amber-700"'
        . ' data-testid="shop-buy-preview-unavailable">'
        . e(__('shop_blox_unavailable_preview')) . '</div>';
}
