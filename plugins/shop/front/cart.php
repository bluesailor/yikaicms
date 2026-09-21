<?php
/**
 * 商城 - 购物车页（/shop/cart，M1-b）。
 *
 * 经 dispatch_routes 由 Dispatcher require（index.php 已加载 init）。也可直接
 * 访问（行为一致）。安全三件套：
 * 1. 本页**不调用 HtmlCache::start()**——私有页不进整页缓存（读/写两侧都不会）；
 * 2. 显式 Cache-Control: no-store——中间代理与浏览器也不得存；
 * 3. 价格一律现场从库重算（立项红线：购物车价格不可信），session 里只有 [id, qty]。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
require_once ROOT_PATH . '/includes/init.php';
require_once ROOT_PATH . '/plugins/shop/lib/money.php';
require_once ROOT_PATH . '/plugins/shop/lib/tables.php';
require_once ROOT_PATH . '/plugins/shop/lib/sales.php';
require_once ROOT_PATH . '/plugins/shop/lib/cart.php';

header('Cache-Control: no-store');

// 插件停用/未初始化时表不存在：购物车页退化为空车（产品展示不受影响的红线）
try {
    shopEnsureSchema();
    $schemaReady = true;
} catch (Throwable $e) {
    $schemaReady = false;
    error_log('[shop] ensure schema failed on cart page: ' . $e->getMessage());
}

$lines = $schemaReady ? shopCartLines() : [];
$rows = [];
$totalCents = 0;
$secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
$cartTokenTs = time();
$cartTokenSig = FormSubmissionToken::sign('shop_cart', $cartTokenTs, $secret);

foreach ($lines as $line) {
    $product = shopResolveProductRow($line['id']);
    if ($product === null) {
        continue;   // 产品已删：行静默跳过（车里的孤儿行在下次写入时自然清理）
    }
    $sales = shopCartSalesLookupDefault($line['id']);
    if ($sales === null) {
        continue;   // 已下架：不再展示购买入口，也不计入小计
    }
    $unitCents = shopEffectivePriceCents($product, $sales);
    $subtotalCents = shopMoneyMultiply($unitCents, $line['qty']);
    $totalCents = shopMoneySum([$totalCents, $subtotalCents]);
    $rows[] = [
        'id' => $line['id'],
        'qty' => $line['qty'],
        'title' => (string) $product['title'],
        'model' => (string) ($product['model'] ?? ''),
        'cover' => (string) ($product['cover'] ?? ''),
        'url' => function_exists('productPrettyUrl') ? productPrettyUrl($product) : '',
        'sku' => (string) ($sales['sku'] ?? ''),
        'stock' => (int) $sales['stock'],
        'unit_decimal' => shopCentsToDecimal($unitCents),
        'subtotal_decimal' => shopCentsToDecimal($subtotalCents),
    ];
}

$pageTitle = __('shop_cart_title');
$currentSlug = 'product';
$navChannels = function_exists('getNavChannels') ? getNavChannels() : [];
$productChannel = function_exists('getChannelBySlug') ? getChannelBySlug('product', true) : null;
$continueUrl = $productChannel ? channelUrl($productChannel) : '/';
require_once theme_path('layouts/header.php');
?>

<div class="bg-gray-100 py-8">
    <div class="container mx-auto px-4 max-w-5xl">
        <h1 class="text-2xl font-bold text-gray-900 mb-6" data-testid="shop-cart-title"><?php echo e(__('shop_cart_title')); ?></h1>

        <?php if ($rows === []): ?>
        <div class="bg-white rounded border border-gray-200 px-6 py-16 text-center" data-testid="shop-cart-empty">
            <p class="text-gray-500 mb-4"><?php echo e(__('shop_cart_empty')); ?></p>
            <a href="<?php echo e($continueUrl); ?>" class="inline-block text-primary hover:underline"><?php echo e(__('shop_cart_continue')); ?></a>
        </div>
        <?php else: ?>
        <div class="bg-white rounded border border-gray-200 overflow-x-auto" data-testid="shop-cart-list">
            <table class="w-full text-sm">
                <thead>
                <tr class="bg-gray-50 text-left text-gray-500">
                    <th class="px-4 py-3 font-medium"><?php echo e(__('shop_cart_item')); ?></th>
                    <th class="px-4 py-3 font-medium"><?php echo e(__('shop_col_price_display')); ?></th>
                    <th class="px-4 py-3 font-medium"><?php echo e(__('shop_col_qty')); ?></th>
                    <th class="px-4 py-3 font-medium text-right"><?php echo e(__('shop_cart_subtotal')); ?></th>
                    <th class="px-4 py-3"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                <tr class="border-t border-gray-100" data-testid="shop-cart-row-<?php echo (int) $row['id']; ?>">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($row['cover'] !== ''): ?>
                            <img src="<?php echo e($row['cover']); ?>" alt="" class="w-14 h-14 object-cover rounded border border-gray-200">
                            <?php endif; ?>
                            <div class="min-w-0">
                                <?php if ($row['url'] !== ''): ?>
                                <a href="<?php echo e($row['url']); ?>" class="font-medium text-gray-900 hover:text-primary"><?php echo e($row['title']); ?></a>
                                <?php else: ?>
                                <span class="font-medium text-gray-900"><?php echo e($row['title']); ?></span>
                                <?php endif; ?>
                                <div class="text-xs text-gray-400">
                                    <?php echo e($row['model']); ?><?php echo $row['sku'] !== '' ? ' · ' . e($row['sku']) : ''; ?>
                                    · <?php echo e(__('shop_stock_left', ['n' => (string) $row['stock']])); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-700"><?php echo e(formatPrice($row['unit_decimal'])); ?></td>
                    <td class="px-4 py-3">
                        <form method="post" action="/shop/api" class="flex items-center gap-1">
                            <input type="hidden" name="op" value="set">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <input type="hidden" name="ts" value="<?php echo (int) $cartTokenTs; ?>">
                            <input type="hidden" name="sig" value="<?php echo e($cartTokenSig); ?>">
                            <input type="number" name="qty" min="0" max="999" step="1" value="<?php echo (int) $row['qty']; ?>"
                                   class="border border-gray-300 rounded px-2 py-1 text-sm w-20" data-testid="shop-cart-qty-<?php echo (int) $row['id']; ?>">
                            <button type="submit" class="text-xs px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?php echo e(__('shop_btn_update')); ?></button>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-gray-900"><?php echo e(formatPrice($row['subtotal_decimal'])); ?></td>
                    <td class="px-4 py-3 text-right">
                        <form method="post" action="/shop/api">
                            <input type="hidden" name="op" value="set">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <input type="hidden" name="qty" value="0">
                            <input type="hidden" name="ts" value="<?php echo (int) $cartTokenTs; ?>">
                            <input type="hidden" name="sig" value="<?php echo e($cartTokenSig); ?>">
                            <button type="submit" class="text-xs text-red-500 hover:underline" data-testid="shop-cart-remove-<?php echo (int) $row['id']; ?>"><?php echo e(__('shop_btn_remove')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500" data-testid="shop-cart-count"><?php echo e(__('shop_cart_total_items', ['n' => (string) shopCartCount()])); ?></p>
            <div class="text-lg font-bold text-gray-900" data-testid="shop-cart-total">
                <?php echo e(__('shop_cart_subtotal')); ?>：<?php echo e(formatPrice(shopCentsToDecimal($totalCents))); ?>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-400"><?php echo e(__('shop_cart_hint')); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once theme_path('layouts/footer.php'); ?>
