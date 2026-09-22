<?php
/**
 * 轻量商城 - 管理页面（M1-a + 加固：评审 P1-1/P1-2/P2-1/P2-2）。
 *
 * 由 /admin/plugin_page.php?plugin=shop 加载：宿主页已完成 checkLogin +
 * requirePermission(shop_manage)（plugin.json 声明的权限键，G1 机制）。
 *
 * 关键语义（评审后定案）：
 * - 售价：**只有空字符串表示继承产品价**；'0'/'0.00' 一律视为非法（实物商品
 *   售价必须为正），不再出现「两种零写法结果不同」。
 * - 多语言：销售配置挂翻译组（shopCanonicalProductId），各语言版本共享
 *   SKU/售价/库存。
 * - 列表筛选/分页在 SQL 层完成（lib/sales.php）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib/money.php';
require_once __DIR__ . '/lib/tables.php';
require_once __DIR__ . '/lib/sales.php';
require_once __DIR__ . '/lib/orders.php';
require_once __DIR__ . '/lib/refunds.php';
require_once __DIR__ . '/lib/shipping.php';

try {
    shopEnsureSchema();
} catch (Throwable $schemaError) {
    // 固定提示给用户，细节进日志——原始异常可能带表名/SQL/驱动信息（评审 P2-2）
    error_log('[shop] ensure schema failed: ' . $schemaError->getMessage());
    die('<div style="padding:40px">' . e(__('shop_sales_title')) . ' — ' . e(__('shop_err_schema')) . '</div>');
}

// 视图切换：sales 需要 shop_manage；orders 需要 shop_manage 或 shop_orders
//（宿主页已按 admin_permission_any 放行任一，这里再做页内细分——同一认证路径内的授权）
$shopViewRaw = (string) ($_GET['view'] ?? 'sales');
$shopView = in_array($shopViewRaw, ['sales', 'orders'], true) ? $shopViewRaw : 'sales';
$shopCanManage = hasPermission('shop_manage');
$shopCanOrders = hasPermission('shop_orders');
if ($shopView === 'sales' && !$shopCanManage) {
    $shopView = 'orders';   // 仅有订单权限的运营人员直接落在订单页
}
if ($shopView === 'orders' && !$shopCanOrders && !$shopCanManage) {
    permissionDenied();
}

// ============================================================
// POST：退款操作（登记/确认/拒绝）——shop_orders 或 shop_manage
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_starts_with((string) ($_POST['action'] ?? ''), 'refund_')) {
    verifyCsrf();
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $action = (string) $_POST['action'];
    $refundTarget = $action === 'refund_confirm' ? 'confirmed' : 'rejected';
    $result = match ($action) {
        'refund_create' => shopRefundCreate(
            (int) ($_POST['order_id'] ?? 0),
            trim((string) ($_POST['amount'] ?? '')),
            (string) ($_POST['reason'] ?? ''),
            $adminId
        ),
        'refund_confirm', 'refund_reject' => shopRefundUpdate(
            (int) ($_POST['refund_id'] ?? 0),
            $refundTarget,
            (string) ($_POST['admin_note'] ?? ''),
            $adminId
        ),
        default => ['ok' => false, 'error' => 'shop_err_op'],
    };
    if ($result['ok']) {
        adminLog('shop', $action, 'order #' . (int) ($_POST['order_id'] ?? 0));
    }
    header('Location: /admin/plugin_page.php?plugin=shop&view=orders&detail=' . (int) ($_POST['order_id'] ?? 0)
        . ($result['ok'] ? '&done=1' : '&err=' . urlencode(__($result['error']))), true, 303);
    exit;
}

// ============================================================
// POST：运费设置（固定运费 / 满额包邮阈值 / 订单超时分钟数）
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_shipping') {
    if (!$shopCanManage) {
        permissionDenied();
    }
    verifyCsrf();

    $feeInput = trim((string) ($_POST['shipping_fee'] ?? ''));
    $thresholdInput = trim((string) ($_POST['free_threshold'] ?? ''));
    $expireInput = trim((string) ($_POST['expire_minutes'] ?? ''));
    $excludedProvincesInput = is_array($_POST['excluded_provinces'] ?? null)
        ? $_POST['excluded_provinces']
        : [];
    $excludedRegionsInput = (string) ($_POST['excluded_regions'] ?? '');
    $carriersInput = (string) ($_POST['shipping_carriers'] ?? '');

    // 运费两项允许 0/空（空=0 元），但必须是非负金额格式；超时分钟数 1..10080
    $error = '';
    $feeCents = 0;
    $thresholdCents = 0;
    if ($feeInput !== '') {
        try {
            $feeCents = shopMoneyToCents($feeInput);
        } catch (Throwable $e) {
            $error = __('shop_err_price');
        }
        if ($error === '' && ($feeCents < 0 || $feeCents > shopMoneyMaxCents())) {
            $error = __('shop_err_price');
        }
    }
    if ($error === '' && $thresholdInput !== '') {
        try {
            $thresholdCents = shopMoneyToCents($thresholdInput);
        } catch (Throwable $e) {
            $error = __('shop_err_price');
        }
        if ($error === '' && ($thresholdCents < 0 || $thresholdCents > shopMoneyMaxCents())) {
            $error = __('shop_err_price');
        }
    }
    $expireMinutes = $expireInput === '' ? 30 : (int) $expireInput;
    if ($error === '' && ($expireMinutes < 1 || $expireMinutes > 10080)) {
        $error = __('shop_err_expire');
    }

    $excludedProvinces = [];
    if ($error === '') {
        foreach ($excludedProvincesInput as $province) {
            if (!is_string($province) || !in_array($province, shopMainlandProvinces(), true)) {
                $error = __('shop_err_shipping_regions');
                break;
            }
            $excludedProvinces[$province] = $province;
        }
    }
    $regionConfig = shopNormalizeExcludedRegionConfig($excludedRegionsInput);
    if ($error === '' && !$regionConfig['ok']) {
        $error = __($regionConfig['error']);
    }
    $carrierConfig = shopNormalizeCarrierConfig($carriersInput);
    if ($error === '' && !$carrierConfig['ok']) {
        $error = __($carrierConfig['error']);
    }

    if ($error !== '') {
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode($error));
        exit;
    }

    settingModel()->saveBatch([
        'shop_shipping_fee_cents' => (string) $feeCents,
        'shop_free_shipping_threshold_cents' => (string) $thresholdCents,
        'shop_order_expire_minutes' => (string) $expireMinutes,
        'shop_shipping_excluded_provinces' => json_encode(array_values($excludedProvinces), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'shop_shipping_excluded_regions' => $regionConfig['value'],
        'shop_shipping_carriers' => $carrierConfig['value'],
    ]);
    adminLog(
        'shop',
        'save_shipping',
        "shipping fee={$feeCents} free_threshold={$thresholdCents} expire={$expireMinutes}min"
        . ' excluded_provinces=' . count($excludedProvinces)
        . ' excluded_regions=' . count($regionConfig['rules'])
        . ' carriers=' . count($carrierConfig['carriers'])
    );
    do_action('data_changed');

    header('Location: /admin/plugin_page.php?plugin=shop&saved=1');
    exit;
}

// ============================================================
// POST：订单操作（收款确认/发货/完成/关闭/备注）——shop_orders 或 shop_manage
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_starts_with((string) ($_POST['action'] ?? ''), 'order_')) {
    verifyCsrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = (string) $_POST['action'];
    $result = match ($action) {
        'order_paid' => shopOrderMarkPaid($orderId),
        'order_ship' => shopOrderTransition(
            $orderId,
            'shipped',
            trim((string) ($_POST['tracking_company'] ?? '')),
            trim((string) ($_POST['tracking_no'] ?? ''))
        ),
        'order_complete' => shopOrderTransition($orderId, 'completed'),
        'order_close' => shopOrderClose($orderId),
        'order_remark' => shopOrderUpdateRemark($orderId, (string) ($_POST['remark'] ?? '')),
        default => ['ok' => false, 'error' => 'shop_err_op'],
    };
    if ($result['ok']) {
        adminLog('shop', $action, 'order #' . $orderId);
        if (in_array($action, ['order_paid', 'order_ship', 'order_complete', 'order_close'], true)) {
            do_action('shop_order_status_changed', $orderId, $action);
        }
    }
    header('Location: /admin/plugin_page.php?plugin=shop&view=orders&detail=' . $orderId
        . ($result['ok'] ? '&done=1' : '&err=' . urlencode(__($result['error']))), true, 303);
    exit;
}

// ============================================================
// POST：保存单个产品的销售配置（普通表单 + PRG，无需 JS）
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_sales') {
    if (!$shopCanManage) {
        permissionDenied();
    }
    verifyCsrf();

    $productId = (int) ($_POST['product_id'] ?? 0);
    $product = $productId > 0 ? productModel()->find($productId) : null;
    if (!$product || ($product['deleted_at'] ?? null) !== null) {
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode(__('shop_err_product')));
        exit;
    }
    // 多语言共享：写入翻译组键，而不是当前语言行的 id
    $salesKey = shopCanonicalProductId($product);

    $sku = trim((string) ($_POST['sku'] ?? ''));
    $priceRaw = trim((string) ($_POST['price'] ?? ''));
    $stockRaw = (string) ($_POST['stock'] ?? '');
    $status = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;

    $error = '';
    if (mb_strlen($sku) > 64) {
        $error = __('shop_err_sku_len');
    } elseif ($priceRaw === '') {
        $priceCents = null;   // 空 = 继承产品价（唯一的继承写法）
    } else {
        // '0'、负数、超 decimal(10,2)、超长串、畸形 → 统一非法文案
        $priceCents = shopValidSalePriceCents($priceRaw);
        if ($priceCents === null) {
            $error = __('shop_err_price');
        }
    }
    if ($error === '') {
        $stock = shopValidStock($stockRaw);
        if ($stock === null) {
            $error = __('shop_err_stock');
        }
    }
    if ($error !== '') {
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode($error));
        exit;
    }

    $now = time();
    $data = [
        'sku' => $sku,
        'price' => $priceCents !== null ? shopCentsToDecimal($priceCents) : null,
        'stock' => $stock,
        'status' => $status,
        'updated_at' => $now,
    ];
    $exists = db()->fetchOne(
        'SELECT product_id FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?',
        [$salesKey]
    );
    if ($exists) {
        db()->update('shop_products', $data, 'product_id = ?', [$salesKey]);
    } else {
        $data['product_id'] = $salesKey;
        $data['created_at'] = $now;
        db()->insert('shop_products', $data);
    }

    adminLog('shop', 'save_sales', 'Save sales config for product #' . $productId . ' (key ' . $salesKey . ') status=' . $status . ' stock=' . $stock);
    do_action('data_changed');

    header('Location: /admin/plugin_page.php?plugin=shop&saved=1');
    exit;
}

// ============================================================
// 列表：筛选与分页全部在数据层（lib/sales.php）
// ============================================================
$keyword = trim((string) ($_GET['keyword'] ?? ''));
$salesFilterRaw = (string) ($_GET['sales'] ?? 'all');
$salesFilter = in_array($salesFilterRaw, ['all', 'on', 'off'], true) ? $salesFilterRaw : 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$list = shopSalesPage(['keyword' => $keyword, 'sales' => $salesFilter], $perPage, ($page - 1) * $perPage);
$items = $list['items'];
$totalPages = max(1, (int) ceil($list['total'] / $perPage));

/** 行的展示售价：覆盖价 > 产品价（均为两位小数字符串）。 */
$rowPrice = static fn(array $row): string
    => ($row['sale_price'] !== null && (string) $row['sale_price'] !== '')
        ? (string) $row['sale_price']
        : number_format((float) $row['price'], 2, '.', '');

$currentMenu = 'shop_sales';
$pageTitle = $shopView === 'orders' ? __('shop_nav_orders') : __('shop_sales_title');

// 订单视图数据（懒取：sales 视图不查订单表）
$orderPage = null;
$orderStatusFilter = 'all';
$orderKeyword = '';
$orderDetail = null;
if ($shopView === 'orders') {
    $orderStatusFilterRaw = (string) ($_GET['status'] ?? 'all');
    $orderStatusFilter = in_array($orderStatusFilterRaw, array_merge(['all'], shopOrderStatuses()), true)
        ? $orderStatusFilterRaw : 'all';
    $orderKeyword = trim((string) ($_GET['keyword'] ?? ''));
    $orderPageNo = max(1, (int) ($_GET['page'] ?? 1));
    $orderPage = shopOrderPage(
        ['status' => $orderStatusFilter, 'keyword' => $orderKeyword],
        30,
        ($orderPageNo - 1) * 30
    );
    $orderTotalPages = max(1, (int) ceil($orderPage['total'] / 30));
    $detailId = (int) ($_GET['detail'] ?? 0);
    if ($detailId > 0) {
        $orderDetail = shopOrderDetail($detailId);
    }
}
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <?php // 视图切换（sales 仅 shop_manage 可见） ?>
    <div class="mb-4 flex items-center gap-2 text-sm border-b border-gray-200 pb-2">
        <?php if ($shopCanManage): ?>
        <a href="/admin/plugin_page.php?plugin=shop&view=sales"
           class="px-3 py-1.5 rounded <?php echo $shopView === 'sales' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>"
           data-testid="shop-tab-sales"><?php echo e(__('shop_nav_sales')); ?></a>
        <?php endif; ?>
        <?php if ($shopCanOrders || $shopCanManage): ?>
        <a href="/admin/plugin_page.php?plugin=shop&view=orders"
           class="px-3 py-1.5 rounded <?php echo $shopView === 'orders' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>"
           data-testid="shop-tab-orders"><?php echo e(__('shop_nav_orders')); ?></a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['saved']) || isset($_GET['done'])): ?>
    <div class="mb-4 rounded border border-green-200 bg-green-50 text-green-700 px-3 py-2 text-sm" data-testid="shop-saved-tip"><?php echo e(__('shop_saved')); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] !== ''): ?>
    <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm" data-testid="shop-error-tip"><?php echo e((string) $_GET['err']); ?></div>
    <?php endif; ?>

    <?php if ($shopView === 'orders'): ?>
    <?php // ================= 订单管理视图 ================= ?>
    <?php if ($orderDetail !== null): ?>
    <?php $od = $orderDetail['order']; $contact = json_decode((string) $od['contact_json'], true) ?: []; $address = json_decode((string) $od['address_json'], true) ?: []; ?>
    <div class="bg-white rounded border border-gray-200 overflow-hidden mb-4" data-testid="shop-order-detail">
        <div class="px-5 py-4 border-b bg-gray-50 flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs text-gray-400"><?php echo e(__('shop_order_no')); ?></div>
                <div class="font-medium text-gray-900"><?php echo e((string) $od['order_no']); ?></div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-400"><?php echo e(__('shop_order_status_label')); ?></div>
                <div class="font-medium text-primary" data-testid="shop-order-status"><?php echo e(__('shop_order_status_' . $od['status'])); ?></div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-400"><?php echo e(__('shop_order_status_paid')); ?></div>
                <div><?php echo e($od['payment_status'] === 'succeeded' ? __('shop_order_status_paid') : __('shop_order_status_pending_payment')); ?></div>
            </div>
        </div>
        <div class="p-5 grid md:grid-cols-2 gap-6">
            <div>
                <div class="text-sm font-medium text-gray-700 mb-2"><?php echo e(__('shop_cart_item')); ?></div>
                <?php foreach ($orderDetail['items'] as $item): ?>
                    <?php $snap = json_decode((string) $item['snapshot_json'], true) ?: []; ?>
                <div class="flex justify-between text-sm py-1 border-b border-gray-50">
                    <span><?php echo e((string) ($snap['title'] ?? '')); ?> × <?php echo (int) $item['qty']; ?></span>
                    <span class="text-gray-700"><?php echo e(formatPrice((string) $item['subtotal'])); ?></span>
                </div>
                <?php endforeach; ?>
                <div class="flex justify-between text-sm pt-2">
                    <span class="text-gray-500"><?php echo e(__('shop_checkout_shipping')); ?></span>
                    <span><?php echo e(formatPrice((string) $od['amount_shipping'])); ?></span>
                </div>
                <div class="flex justify-between text-sm font-bold pt-1">
                    <span><?php echo e(__('shop_checkout_total')); ?></span>
                    <span class="text-primary"><?php echo e(formatPrice((string) $od['amount_total'])); ?></span>
                </div>
            </div>
            <div class="text-sm space-y-1">
                <div class="font-medium text-gray-700"><?php echo e(__('shop_checkout_name')); ?> / <?php echo e(__('shop_checkout_phone')); ?></div>
                <div class="text-gray-800"><?php echo e((string) ($contact['name'] ?? '')); ?> · <?php echo e((string) ($contact['phone'] ?? '')); ?></div>
                <div class="text-gray-800"><?php echo e((string) ($contact['email'] ?? '')); ?></div>
                <div class="font-medium text-gray-700 pt-2"><?php echo e(__('shop_checkout_address')); ?></div>
                <div class="text-gray-800"><?php echo e((string) ($address['region'] ?? '')); ?> <?php echo e((string) ($address['address'] ?? '')); ?></div>
                <div class="font-medium text-gray-700 pt-2"><?php echo e(__('shop_checkout_remark')); ?></div>
                <div class="text-gray-800"><?php echo e((string) $od['remark']); ?></div>
                <?php if ((string) ($od['tracking_company'] ?? '') !== '' || (string) ($od['tracking_no'] ?? '') !== ''): ?>
                <div class="font-medium text-gray-700 pt-2"><?php echo e(__('shop_tracking_info')); ?></div>
                <div class="text-gray-800"><?php echo e((string) ($od['tracking_company'] ?? '')); ?> <?php echo e((string) $od['tracking_no']); ?></div>
                <?php endif; ?>
                <div class="text-xs text-gray-400 pt-2"><?php echo e(__('shop_created_at')); ?>：<?php echo e(date('Y-m-d H:i', (int) $od['created_at'])); ?>
                    <?php echo (int) $od['paid_at'] > 0 ? ' · ' . e(__('shop_paid_at')) . '：' . e(date('Y-m-d H:i', (int) $od['paid_at'])) : ''; ?></div>
            </div>
        </div>
        <div class="px-5 pb-5 flex flex-wrap gap-2">
            <?php if ((string) $od['status'] === 'pending_payment'): ?>
            <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="order_paid"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                <button type="submit" class="bg-green-600 text-white text-sm px-4 py-2 rounded hover:bg-green-700" data-testid="shop-order-paid"><?php echo e(__('shop_btn_confirm_paid')); ?></button>
            </form>
            <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="order_close"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                <button type="submit" class="border border-gray-300 text-gray-600 text-sm px-4 py-2 rounded hover:bg-gray-50" data-testid="shop-order-close"><?php echo e(__('shop_btn_close_order')); ?></button>
            </form>
            <?php endif; ?>
            <?php if ((string) $od['status'] === 'awaiting_ship'): ?>
            <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders" class="flex flex-wrap items-center gap-2"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="order_ship"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                <select name="tracking_company" class="border border-gray-300 rounded px-2 py-1.5 text-sm w-36" data-testid="shop-order-tracking-company">
                    <option value=""><?php echo e(__('shop_tracking_none')); ?></option>
                    <?php foreach (shopShippingCarriers() as $carrier): ?>
                    <option value="<?php echo e($carrier); ?>"><?php echo e($carrier); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="tracking_no" maxlength="64" placeholder="<?php echo e(__('shop_tracking_no')); ?>"
                       class="border border-gray-300 rounded px-2 py-1.5 text-sm w-44" data-testid="shop-order-tracking-no">
                <button type="submit" class="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700" data-testid="shop-order-ship"><?php echo e(__('shop_btn_ship')); ?></button>
            </form>
            <?php endif; ?>
            <?php if ((string) $od['status'] === 'shipped'): ?>
            <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="order_complete"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                <button type="submit" class="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700" data-testid="shop-order-complete"><?php echo e(__('shop_btn_complete')); ?></button>
            </form>
            <?php endif; ?>
            <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders" class="flex items-center gap-2 flex-1 min-w-[16rem]"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="order_remark"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                <input type="text" name="remark" value="<?php echo e((string) $od['remark']); ?>" maxlength="500"
                       class="flex-1 border border-gray-300 rounded px-2 py-1.5 text-sm" placeholder="<?php echo e(__('shop_checkout_remark')); ?>">
                <button type="submit" class="border border-gray-300 text-gray-600 text-sm px-3 py-1.5 rounded hover:bg-gray-50"><?php echo e(__('shop_btn_save')); ?></button>
            </form>
            <a href="/admin/plugin_page.php?plugin=shop&view=orders" class="border border-gray-300 text-gray-600 text-sm px-3 py-1.5 rounded hover:bg-gray-50 inline-flex items-center">← <?php echo e(__('shop_back_to_list')); ?></a>
        </div>

        <?php // 退款（独立状态机，M2-c）：已收款未关闭的订单可登记退款；确认/拒绝不自动改订单状态 ?>
        <?php $refundableCents = shopRefundableCents((int) $od['id']); $refunds = shopRefundsByOrder((int) $od['id']); ?>
        <div class="px-5 pb-5 border-t border-gray-100" data-testid="shop-refund-block">
            <div class="text-sm font-medium text-gray-700 pt-4 pb-2"><?php echo e(__('shop_refund_title')); ?></div>
            <?php foreach ($refunds as $rf): ?>
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm py-1.5 border-b border-gray-50" data-testid="shop-refund-row-<?php echo (int) $rf['id']; ?>">
                <span><?php echo e(formatPrice((string) $rf['amount'])); ?> · <?php echo e(__('shop_refund_status_' . $rf['status'])); ?></span>
                <span class="text-xs text-gray-500"><?php echo e((string) $rf['reason']); ?><?php echo ($rf['admin_note'] ?? '') !== '' ? ' / ' . e((string) $rf['admin_note']) : ''; ?></span>
                <?php if ((string) $rf['status'] === 'requested'): ?>
                <span class="flex gap-2">
                    <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders"><?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="refund_confirm"><input type="hidden" name="refund_id" value="<?php echo (int) $rf['id']; ?>"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                        <input type="hidden" name="admin_note" value="">
                        <button type="submit" class="text-xs px-2 py-1 rounded bg-green-600 text-white hover:bg-green-700" data-testid="shop-refund-confirm-<?php echo (int) $rf['id']; ?>"><?php echo e(__('shop_refund_confirm')); ?></button>
                    </form>
                    <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders"><?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="refund_reject"><input type="hidden" name="refund_id" value="<?php echo (int) $rf['id']; ?>"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                        <input type="hidden" name="admin_note" value="">
                        <button type="submit" class="text-xs px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50" data-testid="shop-refund-reject-<?php echo (int) $rf['id']; ?>"><?php echo e(__('shop_refund_reject')); ?></button>
                    </form>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($refundableCents > 0): ?>
            <form method="post" action="/admin/plugin_page.php?plugin=shop&view=orders" class="flex flex-wrap items-center gap-2 pt-2"><?php echo csrfField(); ?>
                <input type="hidden" name="action" value="refund_create"><input type="hidden" name="order_id" value="<?php echo (int) $od['id']; ?>">
                <input type="text" name="amount" placeholder="<?php echo e(__('shop_refund_amount_ph', ['max' => formatPrice(shopCentsToDecimal($refundableCents))])); ?>"
                       class="border border-gray-300 rounded px-2 py-1.5 text-sm w-28" data-testid="shop-refund-amount">
                <input type="text" name="reason" maxlength="500" placeholder="<?php echo e(__('shop_refund_reason')); ?>"
                       class="border border-gray-300 rounded px-2 py-1.5 text-sm flex-1 min-w-[10rem]">
                <button type="submit" class="bg-amber-600 text-white text-sm px-4 py-1.5 rounded hover:bg-amber-700" data-testid="shop-refund-create"><?php echo e(__('shop_refund_create')); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <form method="get" action="/admin/plugin_page.php" class="mb-4 flex flex-wrap items-center gap-2">
        <input type="hidden" name="plugin" value="shop"><input type="hidden" name="view" value="orders">
        <input type="text" name="keyword" value="<?php echo e($orderKeyword); ?>" placeholder="<?php echo e(__('shop_order_search_placeholder')); ?>"
               class="border border-gray-300 rounded px-3 py-1.5 text-sm w-64">
        <select name="status" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="all"><?php echo e(__('shop_filter_all')); ?></option>
            <?php foreach (shopOrderStatuses() as $st): ?>
            <option value="<?php echo e($st); ?>"<?php echo $orderStatusFilter === $st ? ' selected' : ''; ?>><?php echo e(__('shop_order_status_' . $st)); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-blue-600 text-white text-sm px-4 py-1.5 rounded hover:bg-blue-700"><?php echo e(__('shop_btn_search')); ?></button>
    </form>
    <div class="bg-white rounded border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="bg-gray-50 text-left text-gray-500">
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_order_no')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_order_status_label')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_order_status_paid')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_checkout_name')); ?> / <?php echo e(__('shop_checkout_phone')); ?></th>
                <th class="px-3 py-2 font-medium text-right"><?php echo e(__('shop_checkout_total')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_created_at')); ?></th>
                <th class="px-3 py-2"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orderPage['items'] as $o): ?>
                <?php $c = json_decode((string) $o['contact_json'], true) ?: []; ?>
            <tr class="border-t border-gray-100" data-testid="shop-order-row-<?php echo (int) $o['id']; ?>">
                <td class="px-3 py-2 font-mono text-xs"><?php echo e((string) $o['order_no']); ?></td>
                <td class="px-3 py-2"><?php echo e(__('shop_order_status_' . $o['status'])); ?></td>
                <td class="px-3 py-2"><?php echo e($o['payment_status'] === 'succeeded' ? __('shop_order_status_paid') : __('shop_order_status_pending_payment')); ?></td>
                <td class="px-3 py-2"><?php echo e((string) ($c['name'] ?? '')); ?> · <?php echo e((string) ($c['phone'] ?? '')); ?></td>
                <td class="px-3 py-2 text-right font-medium"><?php echo e(formatPrice((string) $o['amount_total'])); ?></td>
                <td class="px-3 py-2 text-xs text-gray-500"><?php echo e(date('Y-m-d H:i', (int) $o['created_at'])); ?></td>
                <td class="px-3 py-2 text-right">
                    <a href="/admin/plugin_page.php?plugin=shop&view=orders&detail=<?php echo (int) $o['id']; ?>" class="text-primary hover:underline text-xs"><?php echo e(__('shop_order_detail')); ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($orderPage['items'] === []): ?>
            <tr class="border-t border-gray-100"><td colspan="7" class="px-3 py-8 text-center text-gray-400">—</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($orderTotalPages > 1): ?>
    <div class="mt-4 flex items-center gap-2 text-sm">
        <?php for ($p = 1; $p <= $orderTotalPages; $p++): ?>
            <?php if ($p === $orderPageNo): ?>
            <span class="px-3 py-1 rounded bg-blue-600 text-white"><?php echo $p; ?></span>
            <?php else: ?>
            <a class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"
               href="/admin/plugin_page.php?plugin=shop&view=orders&status=<?php echo e($orderStatusFilter); ?>&keyword=<?php echo rawurlencode($orderKeyword); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php else: ?>
    <?php // ================= 销售设置视图（原有内容） ================= ?>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('shop_sales_title')); ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('shop_sales_desc')); ?></p>
        </div>
    </div>

    <?php // 运费、服务区、快递公司与超时：先于产品列表，商家一次配好 ?>
    <?php
    $shopExcludedProvinces = json_decode((string) config('shop_shipping_excluded_provinces', '[]'), true);
    $shopExcludedProvinces = is_array($shopExcludedProvinces) ? $shopExcludedProvinces : [];
    $shopExcludedRegions = (string) config('shop_shipping_excluded_regions', '');
    $shopCarriers = (string) config('shop_shipping_carriers', implode("\n", shopDefaultShippingCarriers()));
    ?>
    <div class="mb-4 bg-white rounded border border-gray-200 p-4" data-testid="shop-shipping-settings">
        <div class="text-sm font-medium text-gray-900 mb-3"><?php echo e(__('shop_shipping_title')); ?></div>
        <form method="post" action="/admin/plugin_page.php?plugin=shop" class="space-y-4 text-sm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_shipping">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-gray-600 mb-1"><?php echo e(__('shop_shipping_fee')); ?></label>
                    <input type="text" name="shipping_fee" value="<?php echo e(shopCentsToDecimal((int) config('shop_shipping_fee_cents', 1500))); ?>"
                           class="border border-gray-300 rounded px-2 py-1.5 w-28" data-testid="shop-shipping-fee">
                </div>
                <div>
                    <label class="block text-gray-600 mb-1"><?php echo e(__('shop_shipping_free_threshold')); ?></label>
                    <input type="text" name="free_threshold" value="<?php echo (int) config('shop_free_shipping_threshold_cents', 0) > 0 ? e(shopCentsToDecimal((int) config('shop_free_shipping_threshold_cents', 0))) : ''; ?>"
                           placeholder="<?php echo e(__('shop_shipping_free_off')); ?>"
                           class="border border-gray-300 rounded px-2 py-1.5 w-28" data-testid="shop-shipping-threshold">
                </div>
                <div>
                    <label class="block text-gray-600 mb-1"><?php echo e(__('shop_expire_minutes')); ?></label>
                    <input type="number" name="expire_minutes" min="1" max="10080" step="1" value="<?php echo (int) config('shop_order_expire_minutes', 30); ?>"
                           class="border border-gray-300 rounded px-2 py-1.5 w-24" data-testid="shop-expire-minutes">
                </div>
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-1"><?php echo e(__('shop_shipping_excluded_provinces')); ?></div>
                <p class="text-xs text-gray-400 mb-2"><?php echo e(__('shop_shipping_excluded_provinces_hint')); ?></p>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2 rounded border border-gray-200 p-3 max-h-48 overflow-y-auto" data-testid="shop-shipping-excluded-provinces">
                    <?php foreach (shopMainlandProvinces() as $province): ?>
                    <label class="flex items-center gap-1.5 text-gray-600">
                        <input type="checkbox" name="excluded_provinces[]" value="<?php echo e($province); ?>"<?php echo in_array($province, $shopExcludedProvinces, true) ? ' checked' : ''; ?>>
                        <span><?php echo e($province); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block font-medium text-gray-700 mb-1"><?php echo e(__('shop_shipping_excluded_regions')); ?></label>
                    <textarea name="excluded_regions" rows="6" maxlength="10000"
                              placeholder="<?php echo e(__('shop_shipping_excluded_regions_placeholder')); ?>"
                              class="w-full border border-gray-300 rounded px-3 py-2 text-sm font-mono" data-testid="shop-shipping-excluded-regions"><?php echo e($shopExcludedRegions); ?></textarea>
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('shop_shipping_excluded_regions_hint')); ?></p>
                </div>
                <div>
                    <label class="block font-medium text-gray-700 mb-1"><?php echo e(__('shop_shipping_carriers')); ?></label>
                    <textarea name="shipping_carriers" rows="6" maxlength="3000"
                              class="w-full border border-gray-300 rounded px-3 py-2 text-sm" data-testid="shop-shipping-carriers"><?php echo e($shopCarriers); ?></textarea>
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('shop_shipping_carriers_hint')); ?></p>
                </div>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-1.5 rounded hover:bg-blue-700"><?php echo e(__('shop_btn_save')); ?></button>
        </form>
    </div>

    <form method="get" action="/admin/plugin_page.php" class="mb-4 flex flex-wrap items-center gap-2">
        <input type="hidden" name="plugin" value="shop">
        <input type="text" name="keyword" value="<?php echo e($keyword); ?>" placeholder="<?php echo e(__('shop_placeholder_search')); ?>"
               class="border border-gray-300 rounded px-3 py-1.5 text-sm w-64">
        <select name="sales" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="all"<?php echo $salesFilter === 'all' ? ' selected' : ''; ?>><?php echo e(__('shop_filter_all')); ?></option>
            <option value="on"<?php echo $salesFilter === 'on' ? ' selected' : ''; ?>><?php echo e(__('shop_filter_on')); ?></option>
            <option value="off"<?php echo $salesFilter === 'off' ? ' selected' : ''; ?>><?php echo e(__('shop_filter_off')); ?></option>
        </select>
        <button type="submit" class="bg-blue-600 text-white text-sm px-4 py-1.5 rounded hover:bg-blue-700"><?php echo e(__('shop_btn_search')); ?></button>
    </form>

    <div class="bg-white rounded border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="bg-gray-50 text-left text-gray-500">
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_product')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_price_display')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_sale_price')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_sku')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_stock')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_status')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_action')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <?php $on = (int) ($row['status'] ?? 0) === 1; ?>
                <tr class="border-t border-gray-100 align-middle" data-testid="shop-row-<?php echo (int) $row['id']; ?>">
                <form method="post" action="/admin/plugin_page.php?plugin=shop">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_sales">
                    <input type="hidden" name="product_id" value="<?php echo (int) $row['id']; ?>">
                    <td class="px-3 py-2">
                        <div class="font-medium text-gray-900"><?php echo e((string) $row['title']); ?></div>
                        <div class="text-xs text-gray-400"><?php echo e((string) $row['model']); ?> · <?php echo e((string) $row['lang']); ?><?php echo (int) ($row['sales_key'] ?? 0) > 0 && (int) $row['sales_key'] !== (int) $row['id'] ? ' · ' . e(__('shop_shared_group')) : ''; ?></div>
                    </td>
                    <td class="px-3 py-2 text-gray-500"><?php echo e($rowPrice($row)); ?></td>
                    <td class="px-3 py-2">
                        <input type="text" name="price" value="<?php echo e($row['sale_price'] !== null ? (string) $row['sale_price'] : ''); ?>"
                               placeholder="<?php echo e($rowPrice($row)); ?>"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-24" data-testid="shop-price-<?php echo (int) $row['id']; ?>">
                    </td>
                    <td class="px-3 py-2">
                        <input type="text" name="sku" value="<?php echo e((string) ($row['sku'] ?? '')); ?>"
                               maxlength="64" class="border border-gray-300 rounded px-2 py-1 text-sm w-28">
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" name="stock" min="0" step="1" value="<?php echo e((string) ($row['stock'] ?? '0')); ?>"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-20" data-testid="shop-stock-<?php echo (int) $row['id']; ?>">
                    </td>
                    <td class="px-3 py-2">
                        <label class="inline-flex items-center gap-1 text-sm">
                            <input type="checkbox" name="status" value="1"<?php echo $on ? ' checked' : ''; ?>
                                   data-testid="shop-status-<?php echo (int) $row['id']; ?>">
                            <?php echo e($on ? __('shop_status_on') : __('shop_status_off')); ?>
                        </label>
                    </td>
                    <td class="px-3 py-2">
                        <button type="submit" class="text-sm px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700"><?php echo e(__('shop_btn_save')); ?></button>
                    </td>
                </form>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr class="border-t border-gray-100"><td colspan="7" class="px-3 py-8 text-center text-gray-400">—</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="mt-4 flex items-center gap-2 text-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="px-3 py-1 rounded bg-blue-600 text-white"><?php echo $p; ?></span>
            <?php else: ?>
                <a class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"
                   href="/admin/plugin_page.php?plugin=shop&keyword=<?php echo rawurlencode($keyword); ?>&sales=<?php echo e($salesFilter); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; /* 视图条件：sales */ ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
