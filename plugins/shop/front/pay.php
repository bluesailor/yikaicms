<?php
/** 商城在线支付发起端点：支付宝跳转 / 微信 Native 二维码。 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
require_once ROOT_PATH . '/includes/init.php';
require_once ROOT_PATH . '/plugins/shop/lib/tables.php';
require_once ROOT_PATH . '/plugins/shop/lib/orders.php';
require_once ROOT_PATH . '/plugins/shop/lib/gateways.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('method not allowed');
}

$gateway = (string) ($_POST['gateway'] ?? '');
$orderNo = trim((string) ($_POST['order_no'] ?? ''));
$timestamp = (int) ($_POST['ts'] ?? 0);
$signature = (string) ($_POST['sig'] ?? '');
$secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
$redirect = static function (string $error) use ($orderNo): void {
    header('Location: /shop/order?no=' . rawurlencode($orderNo) . '&err=' . rawurlencode($error), true, 303);
    exit;
};

if (!in_array($gateway, shopEnabledOnlinePaymentGateways(), true)
    || $secret === ''
    || !shopPaymentVerifyStartToken($gateway, $orderNo, $timestamp, $signature, $secret)) {
    $redirect('shop_err_token');
}
shopEnsureSchema();
$order = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_orders WHERE order_no = ?', [$orderNo]);
if ($order === null || !hash_equals('pending_payment', (string) $order['status'])) {
    $redirect('shop_err_order_transition');
}
$payment = db()->fetchOne(
    'SELECT status FROM ' . DB_PREFIX . 'shop_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1',
    [(int) $order['id']]
);
if ($payment !== null && hash_equals('succeeded', (string) $payment['status'])) {
    $redirect('shop_err_order_transition');
}

if ($gateway === 'wechat_pay') {
    $result = shopWechatCreateNativeOrder($order);
    if (!$result['ok']) {
        $redirect($result['error']);
    }
    $_SESSION['shop_wechat_native'] = [
        'order_no' => $orderNo,
        'code_url' => (string) $result['code_url'],
        'created_at' => time(),
    ];
    header('Location: /shop/order?no=' . rawurlencode($orderNo) . '&pay=wechat_pay', true, 303);
    exit;
}

$params = shopAlipayPagePayParameters($order);
if ($params === []) {
    $redirect('shop_err_payment_start');
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title><?php echo e(__('shop_alipay_redirecting')); ?></title></head>
<body>
<form id="alipay-submit" method="post" action="https://openapi.alipay.com/gateway.do">
<?php foreach ($params as $name => $value): ?>
<input type="hidden" name="<?php echo e($name); ?>" value="<?php echo e($value); ?>">
<?php endforeach; ?>
<noscript><button type="submit"><?php echo e(__('shop_alipay_continue')); ?></button></noscript>
</form>
<p><?php echo e(__('shop_alipay_redirecting')); ?></p>
<script>document.getElementById('alipay-submit').submit();</script>
</body></html>
