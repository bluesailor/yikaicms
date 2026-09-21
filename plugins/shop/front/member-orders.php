<?php
/**
 * 商城 - 会员「我的订单」（/member/shop-orders，M2-d）。
 *
 * 经 dispatch_routes 进入；会员登录校验复用 member_auth（与 member/profile.php
 * 同一条认证路径）。会员本人订单直查（member_id 匹配），无需手机尾号。
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

if (!function_exists('isMemberLoggedIn') || !isMemberLoggedIn()) {
    redirect('/member/login.php');
}
$memberId = (int) ($_SESSION['member_id'] ?? 0);
if ($memberId <= 0) {
    doMemberLogout();
    redirect('/member/login.php');
}

try {
    shopEnsureSchema();
} catch (Throwable $e) {
    error_log('[shop] ensure schema failed on member orders: ' . $e->getMessage());
}

// 会员本人全部订单（分页 20），最新支付状态并入
$pageNo = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$orders = DB_PREFIX . 'shop_orders';
$payments = DB_PREFIX . 'shop_payments';
$total = (int) db()->fetchColumn(
    "SELECT COUNT(*) FROM {$orders} WHERE member_id = ?",
    [$memberId]
);
$rows = db()->fetchAll(
    "SELECT o.*, p.status AS payment_status
     FROM {$orders} o
     LEFT JOIN {$payments} p ON p.id = (
         SELECT MAX(p2.id) FROM {$payments} p2 WHERE p2.order_id = o.id
     )
     WHERE o.member_id = ?
     ORDER BY o.id DESC LIMIT ? OFFSET ?",
    [$memberId, $perPage, ($pageNo - 1) * $perPage]
);
$totalPages = max(1, (int) ceil($total / $perPage));

$pageTitle = __('shop_my_orders');
require_once ROOT_PATH . '/includes/header.php';
?>

<main class="flex-1">
<div class="container mx-auto px-4 py-10 max-w-4xl">
    <h1 class="text-xl font-bold text-gray-900 mb-6" data-testid="shop-my-orders-title"><?php echo e(__('shop_my_orders')); ?></h1>

    <?php if ($rows === []): ?>
    <div class="bg-white rounded-lg shadow p-10 text-center text-gray-400" data-testid="shop-my-orders-empty"><?php echo e(__('shop_my_orders_empty')); ?></div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow overflow-hidden" data-testid="shop-my-orders-list">
        <table class="w-full text-sm">
            <thead>
            <tr class="bg-gray-50 text-left text-gray-500 text-xs">
                <th class="px-4 py-3 font-medium"><?php echo e(__('shop_order_no')); ?></th>
                <th class="px-4 py-3 font-medium"><?php echo e(__('shop_order_status_label')); ?></th>
                <th class="px-4 py-3 font-medium"><?php echo e(__('shop_order_status_paid')); ?></th>
                <th class="px-4 py-3 font-medium text-right"><?php echo e(__('shop_checkout_total')); ?></th>
                <th class="px-4 py-3 font-medium"><?php echo e(__('shop_created_at')); ?></th>
                <th class="px-4 py-3"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $o): ?>
            <tr class="border-t border-gray-100" data-testid="shop-my-order-row-<?php echo (int) $o['id']; ?>">
                <td class="px-4 py-3 font-mono text-xs"><?php echo e((string) $o['order_no']); ?></td>
                <td class="px-4 py-3"><?php echo e(__('shop_order_status_' . $o['status'])); ?></td>
                <td class="px-4 py-3"><?php echo e($o['payment_status'] === 'succeeded' ? __('shop_order_status_paid') : __('shop_order_status_pending_payment')); ?></td>
                <td class="px-4 py-3 text-right font-medium"><?php echo e(formatPrice((string) $o['amount_total'])); ?></td>
                <td class="px-4 py-3 text-xs text-gray-500"><?php echo e(date('Y-m-d H:i', (int) $o['created_at'])); ?></td>
                <td class="px-4 py-3 text-right">
                    <a href="/shop/order?no=<?php echo rawurlencode((string) $o['order_no']); ?>" class="text-primary hover:underline text-xs"><?php echo e(__('shop_order_detail')); ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="mt-4 flex items-center gap-2 text-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $pageNo): ?>
            <span class="px-3 py-1 rounded bg-primary text-white"><?php echo $p; ?></span>
            <?php else: ?>
            <a class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"
               href="/member/shop-orders?page=<?php echo $p; ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</main>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
