<?php
/** 商城支付异步通知端点：POST /shop/payment-notify/{gateway}。 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once ROOT_PATH . '/includes/init.php';
require_once ROOT_PATH . '/plugins/shop/lib/payments.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('fail');
}

$gateway = strtolower((string) ($_GET['gateway'] ?? ''));
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > shopPaymentNotifyMaxBytes()) {
    http_response_code(413);
    exit('fail');
}
$rawBody = (string) file_get_contents('php://input');

$headers = [];
if (function_exists('getallheaders')) {
    $incoming = getallheaders();
    if (is_array($incoming)) {
        foreach ($incoming as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[strtolower($name)] = $value;
            }
        }
    }
}

$result = shopPaymentHandleNotification($gateway, $rawBody, [
    'headers' => $headers,
    'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
]);
if ($result['ok']) {
    exit('success');
}

$status = match ($result['error']) {
    'shop_err_payment_unverified' => 401,
    'shop_err_schema', 'shop_err_order_failed' => 503,
    default => 400,
};
http_response_code($status);
exit('fail');
