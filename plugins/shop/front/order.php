<?php
/**
 * 商城 - 订单状态页（/shop/order，M1-c）。
 *
 * 下单成功后跳转至此；后续凭订单号 + 手机尾号（后 4 位）查询。会员登录后
 * 可直接查看自己的订单。展示联系电话一律脱敏（138****5678），库内快照保留
 * 原始值供商家后台使用。
 *
 * 私有页：不调 HtmlCache::start + no-store。
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
require_once ROOT_PATH . '/plugins/shop/lib/orders.php';

header('Cache-Control: no-store');

try {
    shopEnsureSchema();
} catch (Throwable $e) {
    error_log('[shop] ensure schema failed on order page: ' . $e->getMessage());
}

$orderNo = trim((string) ($_GET['no'] ?? ''));
$memberId = (int) ($_SESSION['member_id'] ?? 0);
$phoneTail = trim((string) ($_POST['phone_tail'] ?? ($_GET['pt'] ?? '')));

$lookup = $orderNo !== '' ? shopOrderLookup($orderNo, $phoneTail, $memberId) : ['ok' => false, 'error' => 'shop_err_order_not_found'];
$placed = isset($_GET['placed']);

/** 联系电话脱敏：保留前 3 后 4，中间打码 */
$maskPhone = static function (string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 7) {
        return str_repeat('*', max(4, strlen($phone) - 2));
    }
    return substr($digits, 0, 3) . str_repeat('*', strlen($digits) - 7) . substr($digits, -4);
};

$statusKey = $lookup['ok'] ? 'shop_order_status_' . (string) ($lookup['order']['status'] ?? '') : '';
$paymentStatus = $lookup['ok'] ? (string) ($lookup['payment']['status'] ?? 'created') : 'created';

$pageTitle = __('shop_order_title');
$currentSlug = 'product';
$navChannels = function_exists('getNavChannels') ? getNavChannels() : [];
require_once theme_path('layouts/header.php');
?>

<div class="bg-gray-100 py-8">
    <div class="container mx-auto px-4 max-w-3xl">
        <h1 class="text-2xl font-bold text-gray-900 mb-6"><?php echo e(__('shop_order_title')); ?></h1>

        <?php if ($placed): ?>
        <div class="mb-4 rounded border border-green-200 bg-green-50 text-green-700 px-3 py-2 text-sm" data-testid="shop-order-placed"><?php echo e(__('shop_order_placed_hint')); ?></div>
        <?php endif; ?>

        <?php if (!$lookup['ok']): ?>
        <div class="bg-white rounded border border-gray-200 p-6" data-testid="shop-order-query">
            <p class="text-sm text-gray-500 mb-4"><?php echo e(__('shop_order_query_hint')); ?></p>
            <form method="post" action="/shop/order" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_order_no')); ?></label>
                    <input type="text" name="no" required maxlength="32" value="<?php echo e($orderNo); ?>"
                           class="border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-order-no-input">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('shop_order_phone_tail')); ?></label>
                    <input type="text" name="phone_tail" maxlength="4"
                           class="border border-gray-300 rounded px-3 py-2 text-sm w-24" data-testid="shop-order-phone-input">
                </div>
                <button type="submit" class="bg-primary hover:bg-secondary text-white text-sm px-4 py-2 rounded"><?php echo e(__('shop_order_query')); ?></button>
            </form>
            <?php if ($orderNo !== ''): ?>
            <p class="mt-3 text-sm text-red-600" data-testid="shop-order-lookup-error"><?php echo e(__('shop_err_order_verify')); ?></p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <?php $order = $lookup['order']; $contact = json_decode((string) $order['contact_json'], true) ?: []; $address = json_decode((string) $order['address_json'], true) ?: []; ?>
        <div class="bg-white rounded border border-gray-200 overflow-hidden" data-testid="shop-order-detail">
            <div class="px-5 py-4 border-b bg-gray-50 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs text-gray-400"><?php echo e(__('shop_order_no')); ?></div>
                    <div class="font-medium text-gray-900" data-testid="shop-order-no"><?php echo e((string) $order['order_no']); ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-400"><?php echo e(__('shop_order_status_label')); ?></div>
                    <div class="font-medium text-primary" data-testid="shop-order-status"><?php echo e(__($statusKey)); ?><?php echo $paymentStatus === 'succeeded' ? ' · ' . e(__('shop_order_status_paid')) : ''; ?></div>
                </div>
            </div>
            <div class="p-5">
                <table class="w-full text-sm">
                    <thead>
                    <tr class="text-left text-gray-400 text-xs">
                        <th class="pb-2 font-medium"><?php echo e(__('shop_cart_item')); ?></th>
                        <th class="pb-2 font-medium text-right"><?php echo e(__('shop_col_qty')); ?></th>
                        <th class="pb-2 font-medium text-right"><?php echo e(__('shop_cart_subtotal')); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lookup['items'] as $item): ?>
                        <?php $snap = json_decode((string) $item['snapshot_json'], true) ?: []; ?>
                    <tr class="border-t border-gray-100">
                        <td class="py-2">
                            <?php echo e((string) ($snap['title'] ?? '')); ?>
                            <?php echo ($snap['sku'] ?? '') !== '' ? '<span class="text-gray-400"> · ' . e((string) $snap['sku']) . '</span>' : ''; ?>
                            <div class="text-xs text-gray-400"><?php echo e(formatPrice((string) $item['unit_price'])); ?> × <?php echo (int) $item['qty']; ?></div>
                        </td>
                        <td class="py-2 text-right"><?php echo (int) $item['qty']; ?></td>
                        <td class="py-2 text-right whitespace-nowrap"><?php echo e(formatPrice((string) $item['subtotal'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="border-t border-gray-100">
                        <td class="py-2 text-gray-500"><?php echo e(__('shop_checkout_shipping')); ?></td>
                        <td></td>
                        <td class="py-2 text-right"><?php echo e(formatPrice((string) $order['amount_shipping'])); ?></td>
                    </tr>
                    <tr class="border-t border-gray-100">
                        <td class="py-3 font-medium text-gray-900"><?php echo e(__('shop_checkout_total')); ?></td>
                        <td></td>
                        <td class="py-3 text-right font-bold text-primary"><?php echo e(formatPrice((string) $order['amount_total'])); ?></td>
                    </tr>
                    </tbody>
                </table>

                <div class="mt-5 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-xs text-gray-400 mb-1"><?php echo e(__('shop_checkout_name')); ?> / <?php echo e(__('shop_checkout_phone')); ?></div>
                        <div class="text-gray-800" data-testid="shop-order-contact">
                            <?php echo e((string) ($contact['name'] ?? '')); ?> · <?php echo e($maskPhone((string) ($contact['phone'] ?? ''))); ?>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 mb-1"><?php echo e(__('shop_checkout_address')); ?></div>
                        <div class="text-gray-800" data-testid="shop-order-address">
                            <?php echo e((string) ($address['region'] ?? '')); ?> <?php echo e((string) ($address['address'] ?? '')); ?>
                        </div>
                    </div>
                </div>
                <p class="mt-4 text-xs text-gray-400"><?php echo e(__('shop_order_offline_hint')); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once theme_path('layouts/footer.php'); ?>
