<?php
/**
 * 商城 - 结算页（/shop/checkout，M1-c）。
 *
 * GET：订单预览（现场重算价格/运费）+ 联系与收货表单。
 * POST：校验 → 事务下单（lib/orders.php）→ 跳订单状态页。
 * 私有页：不调 HtmlCache::start + no-store。令牌/防垃圾沿用 FormSubmissionToken。
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
require_once ROOT_PATH . '/plugins/shop/lib/orders.php';
require_once ROOT_PATH . '/plugins/shop/lib/shipping.php';
require_once ROOT_PATH . '/plugins/shop/lib/payment-methods.php';

header('Cache-Control: no-store');

try {
    shopEnsureSchema();
    $schemaReady = true;
} catch (Throwable $e) {
    $schemaReady = false;
    error_log('[shop] ensure schema failed on checkout: ' . $e->getMessage());
}

// ============================================================
// POST：下单
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['op'] ?? '') === 'place_order') {
    $secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
    $ts = (int) ($_POST['ts'] ?? 0);
    $sig = (string) ($_POST['sig'] ?? '');
    if ($secret === '' || !FormSubmissionToken::verify('shop_checkout', $ts, $sig, $secret, false, 7200)) {
        header('Location: /shop/checkout?err=' . urlencode(__('shop_err_token')), true, 303);
        exit;
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $province = trim((string) ($_POST['province'] ?? ''));
    $city = trim((string) ($_POST['city'] ?? ''));
    $district = trim((string) ($_POST['district'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $remark = trim((string) ($_POST['remark'] ?? ''));
    $addressResult = shopValidateShippingAddress([
        'province' => $province,
        'city' => $city,
        'district' => $district,
        'address' => $address,
    ]);

    $errorKey = '';
    if ($name === '' || mb_strlen($name) > 50) {
        $errorKey = 'shop_err_contact_name';
    } elseif (preg_match('/^[0-9+\-\s]{5,20}$/', $phone) !== 1) {
        $errorKey = 'shop_err_contact_phone';
    } elseif ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100)) {
        $errorKey = 'shop_err_contact_email';
    } elseif (!$addressResult['ok']) {
        $errorKey = $addressResult['error'];
    }
    if ($errorKey !== '') {
        header('Location: /shop/checkout?err=' . urlencode(__($errorKey)), true, 303);
        exit;
    }

    $result = shopOrderCreate(
        shopCartLines(),
        ['name' => $name, 'phone' => $phone, 'email' => $email],
        $addressResult['address'],
        $remark
    );
    if (!$result['ok']) {
        header('Location: /shop/checkout?err=' . urlencode(__($result['error'])), true, 303);
        exit;
    }

    header('Location: /shop/order?no=' . urlencode((string) $result['order_no']) . '&placed=1', true, 303);
    exit;
}

// ============================================================
// GET：订单预览
// ============================================================
$lines = $schemaReady ? shopCartLines() : [];
$preview = [];
$goodsCents = 0;
$errorKey = isset($_GET['err']) ? (string) $_GET['err'] : '';

foreach ($lines as $line) {
    $product = shopResolveProductRow($line['id']);
    if ($product === null) {
        continue;
    }
    $sales = shopCartSalesLookupDefault($line['id']);
    if ($sales === null) {
        continue;   // 已下架：与购物车页一致静默跳过
    }
    $unitCents = shopEffectivePriceCents($product, $sales);
    $subtotalCents = shopMoneyMultiply($unitCents, $line['qty']);
    $goodsCents = shopMoneySum([$goodsCents, $subtotalCents]);
    $preview[] = [
        'title' => (string) $product['title'],
        'qty' => $line['qty'],
        'sku' => (string) ($sales['sku'] ?? ''),
        'unit_decimal' => shopCentsToDecimal($unitCents),
        'subtotal_decimal' => shopCentsToDecimal($subtotalCents),
    ];
}
$shippingCents = shopShippingFeeCents($goodsCents);
$totalCents = shopMoneySum([$goodsCents, $shippingCents]);
$freeThreshold = (int) config('shop_free_shipping_threshold_cents', 0);
$manualPaymentConfigured = shopManualPaymentMethods() !== [];

$secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
$tokenTs = time();
$tokenSig = FormSubmissionToken::sign('shop_checkout', $tokenTs, $secret);

$pageTitle = __('shop_checkout_title');
$currentSlug = 'product';
$navChannels = function_exists('getNavChannels') ? getNavChannels() : [];
require_once theme_path('layouts/header.php');
?>

<div class="bg-gray-100 py-8">
    <div class="container mx-auto px-4 max-w-5xl">
        <h1 class="text-2xl font-bold text-gray-900 mb-6" data-testid="shop-checkout-title"><?php echo e(__('shop_checkout_title')); ?></h1>

        <?php if ($errorKey !== '' && isset($_GET['err'])): ?>
        <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm" data-testid="shop-checkout-error"><?php echo e(__($errorKey)); ?></div>
        <?php endif; ?>

        <?php if ($preview === []): ?>
        <div class="bg-white rounded border border-gray-200 px-6 py-16 text-center" data-testid="shop-checkout-empty">
            <p class="text-gray-500 mb-4"><?php echo e(__('shop_cart_empty')); ?></p>
            <a href="/shop/cart" class="inline-block text-primary hover:underline"><?php echo e(__('shop_cart_continue')); ?></a>
        </div>
        <?php else: ?>

        <div class="grid md:grid-cols-2 gap-6 items-start">
            <!-- 订单预览 -->
            <div class="bg-white rounded border border-gray-200 overflow-hidden" data-testid="shop-checkout-preview">
                <div class="px-4 py-3 border-b bg-gray-50 font-medium text-gray-700"><?php echo e(__('shop_checkout_summary')); ?></div>
                <table class="w-full text-sm">
                    <tbody>
                    <?php foreach ($preview as $row): ?>
                    <tr class="border-b border-gray-100">
                        <td class="px-4 py-2">
                            <?php echo e($row['title']); ?>
                            <?php echo $row['sku'] !== '' ? '<span class="text-gray-400"> · ' . e($row['sku']) . '</span>' : ''; ?>
                            <span class="text-gray-400"> × <?php echo (int) $row['qty']; ?></span>
                        </td>
                        <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap"><?php echo e(formatPrice($row['subtotal_decimal'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="border-b border-gray-100">
                        <td class="px-4 py-2 text-gray-500"><?php echo e(__('shop_checkout_shipping')); ?><?php echo $freeThreshold > 0 ? ' <span class="text-gray-400">(' . e(__('shop_checkout_free_hint', ['amount' => formatPrice(shopCentsToDecimal($freeThreshold))])) . ')</span>' : ''; ?></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap" data-testid="shop-checkout-shipping"><?php echo e($shippingCents === 0 ? __('shop_checkout_free') : formatPrice(shopCentsToDecimal($shippingCents))); ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 font-medium text-gray-900"><?php echo e(__('shop_checkout_total')); ?></td>
                        <td class="px-4 py-3 text-right font-bold text-primary text-base whitespace-nowrap" data-testid="shop-checkout-total"><?php echo e(formatPrice(shopCentsToDecimal($totalCents))); ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- 联系与收货 -->
            <form method="post" action="/shop/checkout" class="bg-white rounded border border-gray-200 p-5 space-y-4" data-testid="shop-checkout-form">
                <input type="hidden" name="op" value="place_order">
                <input type="hidden" name="ts" value="<?php echo (int) $tokenTs; ?>">
                <input type="hidden" name="sig" value="<?php echo e($tokenSig); ?>">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_name')); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" maxlength="50" required value="<?php echo e(isset($_GET['name']) ? (string) $_GET['name'] : ''); ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-name">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_phone')); ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="phone" maxlength="20" required
                               class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-phone">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_email')); ?></label>
                        <input type="text" name="email" maxlength="100"
                               class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-email">
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_province')); ?> <span class="text-red-500">*</span></label>
                    <select name="province" required autocomplete="address-level1"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-province">
                        <option value=""><?php echo e(__('shop_checkout_province_select')); ?></option>
                        <?php foreach (shopMainlandProvinces() as $provinceOption): ?>
                        <option value="<?php echo e($provinceOption); ?>"><?php echo e($provinceOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_city')); ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="city" maxlength="50" required autocomplete="address-level2"
                               class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-city">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_district')); ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="district" maxlength="50" required autocomplete="address-level3"
                               class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-district">
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_address')); ?> <span class="text-red-500">*</span></label>
                    <textarea name="address" rows="2" maxlength="300" required autocomplete="street-address"
                              class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-checkout-address"></textarea>
                </div>
                <p class="text-xs text-gray-400" data-testid="shop-shipping-scope-hint"><?php echo e(__('shop_checkout_scope_hint')); ?></p>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_checkout_remark')); ?></label>
                    <textarea name="remark" rows="2" maxlength="500"
                              class="w-full border border-gray-300 rounded px-3 py-2 text-sm"></textarea>
                </div>
                <p class="text-xs text-gray-400" data-testid="shop-checkout-offline-hint"><?php echo e(__($manualPaymentConfigured
                    ? 'shop_checkout_manual_configured_hint'
                    : 'shop_checkout_offline_hint')); ?></p>
                <button type="submit" class="w-full bg-primary hover:bg-secondary text-white px-5 py-2.5 rounded font-medium"
                        data-testid="shop-checkout-submit"><?php echo e(__('shop_checkout_place')); ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once theme_path('layouts/footer.php'); ?>
