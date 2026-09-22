<?php
/**
 * 商城 - 购物车操作端点（POST /shop/api，M1-b）。
 *
 * 防滥用（仿 form_submit.php 先例）：FormSubmissionToken HMAC 时间戳签名
 * （slug=shop_cart，有效期 2 小时），外加数量/行数上限（lib/cart.php 内）。
 * 所有操作后 PRG 重定向回 /shop/cart；失败带 err=语言键 由页面展示。
 * 本端点是 POST，天然不进整页缓存；仍显式 no-store 防中间层。
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

// 注意不能标 never 返回类型（PHP 8.1+）：RuntimeRequirements 守卫要求运行包 8.0 兼容。
// 闭包内 header + exit，调用点之后不可达。
$redirect = static function (string $errorKey = ''): void {
    $target = '/shop/cart' . ($errorKey !== '' ? '?err=' . urlencode($errorKey) : '');
    header('Location: ' . $target, true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

// 令牌校验：签名时间戳防无表单直灌（与询价表单同一套机制）
$secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
$ts = (int) ($_POST['ts'] ?? 0);
$sig = (string) ($_POST['sig'] ?? '');
if ($secret === '' || !FormSubmissionToken::verify('shop_cart', $ts, $sig, $secret, false, 7200)) {
    $redirect('shop_err_token');
}

try {
    shopEnsureSchema();
} catch (Throwable $e) {
    error_log('[shop] ensure schema failed on api: ' . $e->getMessage());
    $redirect('shop_err_schema');
}

$op = (string) ($_POST['op'] ?? '');
if ($op === 'add') {
    // 前端提交的是产品行 id；落车统一转翻译组 canonical 键
    $productId = (int) ($_POST['pid'] ?? 0);
    $qty = (int) ($_POST['qty'] ?? 1);
    $variantId = trim((string) ($_POST['variant'] ?? ''));
    $product = $productId > 0 ? productModel()->find($productId) : null;
    if ($product === null || ($product['deleted_at'] ?? null) !== null) {
        $redirect('shop_err_product');
    }
    $result = shopCartAdd(shopCanonicalProductId($product), $qty, null, $variantId);
    $redirect($result['ok'] ? '' : $result['error']);
}

if ($op === 'set') {
    // id 必须是 canonical 键（cart 页面生成，天然正确）
    $result = shopCartSetQty(
        (int) ($_POST['id'] ?? 0),
        (int) ($_POST['qty'] ?? -1),
        trim((string) ($_POST['variant'] ?? ''))
    );
    $redirect($result['ok'] ? '' : $result['error']);
}

$redirect('shop_err_op');
