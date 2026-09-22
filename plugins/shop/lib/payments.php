<?php
/**
 * 商城在线支付通知入口（M2-a）。
 *
 * 核心不内置任何支付平台密钥或“通用成功参数”。网关插件必须通过
 * `shop_payment_verify` filter 验签，并返回统一字段；未注册验签器时永远拒绝。
 * 核心随后负责金额/币种/订单核对、两层幂等和订单状态推进。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/orders.php';

/** 支付通知正文上限（256 KiB），防止匿名回调端点被大包拖垮。 */
function shopPaymentNotifyMaxBytes(): int
{
    return 262144;
}

/** 网关标识同时用于路由和数据库 varchar(20)，只允许窄 ASCII 集。 */
function shopPaymentGatewayValid(string $gateway): bool
{
    return preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $gateway) === 1;
}

/** 同一正文由同一网关重试时得到同一摘要；网关不同则不会互相碰撞。 */
function shopPaymentNotifyHash(string $gateway, string $rawBody, array $context = []): string
{
    // 微信签名在请求头而非正文中：把验签四头并入幂等键，避免一次缺头/错头请求
    // 永久占住同正文，导致平台随后携正确签名重试仍无法处理。支付宝签名在正文内。
    $signatureContext = '';
    if ($gateway === 'wechat_pay' && is_array($context['headers'] ?? null)) {
        $headers = $context['headers'];
        foreach (['wechatpay-serial', 'wechatpay-timestamp', 'wechatpay-nonce', 'wechatpay-signature'] as $name) {
            $signatureContext .= "\0" . (is_string($headers[$name] ?? null) ? $headers[$name] : '');
        }
    }
    return hash('sha256', $gateway . "\0" . $rawBody . $signatureContext);
}

/**
 * 把已验签的网关结果收敛成商城领域字段。金额只收十进制字符串，禁止浮点。
 *
 * @param mixed $verifiedResult 网关 filter 返回值
 * @return array{ok:bool,error:string,order_no?:string,trade_no?:string,amount_cents?:int,currency?:string}
 */
function shopPaymentNormalizeVerified(mixed $verifiedResult): array
{
    if (!is_array($verifiedResult) || ($verifiedResult['verified'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'shop_err_payment_unverified'];
    }
    if (($verifiedResult['status'] ?? '') !== 'succeeded') {
        return ['ok' => false, 'error' => 'shop_err_payment_status'];
    }

    $orderNo = is_string($verifiedResult['order_no'] ?? null)
        ? trim($verifiedResult['order_no']) : '';
    $tradeNo = is_string($verifiedResult['trade_no'] ?? null)
        ? trim($verifiedResult['trade_no']) : '';
    $currency = is_string($verifiedResult['currency'] ?? null)
        ? strtoupper(trim($verifiedResult['currency'])) : '';
    $amount = $verifiedResult['amount'] ?? null;

    if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $orderNo) !== 1) {
        return ['ok' => false, 'error' => 'shop_err_payment_order_no'];
    }
    if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $tradeNo) !== 1) {
        return ['ok' => false, 'error' => 'shop_err_payment_trade_no'];
    }
    if (!is_string($amount)) {
        return ['ok' => false, 'error' => 'shop_err_payment_amount'];
    }
    try {
        $amountCents = shopMoneyToCents($amount);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'shop_err_payment_amount'];
    }
    if ($amountCents <= 0) {
        return ['ok' => false, 'error' => 'shop_err_payment_amount'];
    }
    if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
        return ['ok' => false, 'error' => 'shop_err_payment_currency'];
    }

    return [
        'ok' => true,
        'error' => '',
        'order_no' => $orderNo,
        'trade_no' => $tradeNo,
        'amount_cents' => $amountCents,
        'currency' => $currency,
    ];
}

/** @return array{ok:bool,error:string,idempotent?:bool,order_id?:int} */
function shopPaymentHandledNotification(array $row): array
{
    $note = (string) ($row['process_note'] ?? '');
    if (str_starts_with($note, 'ok:')) {
        return ['ok' => true, 'error' => '', 'idempotent' => true];
    }
    if (str_starts_with($note, 'error:')) {
        return ['ok' => false, 'error' => substr($note, 6) ?: 'shop_err_payment_notify'];
    }
    return ['ok' => false, 'error' => 'shop_err_payment_notify'];
}

/**
 * 处理一条支付通知。网关适配器契约：
 *
 * add_filter('shop_payment_verify', function ($result, $gateway, $raw, $context) {
 *     // 只处理自己的 $gateway；先用平台公钥/共享密钥验证 $raw，失败返回原 $result。
 *     return ['verified'=>true, 'status'=>'succeeded', 'order_no'=>'...',
 *             'trade_no'=>'...', 'amount'=>'19.90', 'currency'=>'CNY'];
 * });
 *
 * @param array<string,mixed> $context 请求头等只读上下文，由适配器自行取所需项
 * @return array{ok:bool,error:string,idempotent?:bool,order_id?:int}
 */
function shopPaymentHandleNotification(string $gateway, string $rawBody, array $context = []): array
{
    if (!shopPaymentGatewayValid($gateway)) {
        return ['ok' => false, 'error' => 'shop_err_payment_gateway'];
    }
    $rawBytes = strlen($rawBody);
    if ($rawBytes === 0 || $rawBytes > shopPaymentNotifyMaxBytes()
        || !mb_check_encoding($rawBody, 'UTF-8')) {
        return ['ok' => false, 'error' => 'shop_err_payment_payload'];
    }

    try {
        shopEnsureSchema();
    } catch (Throwable $e) {
        error_log('[shop] payment schema unavailable: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'shop_err_schema'];
    }

    $hash = shopPaymentNotifyHash($gateway, $rawBody, $context);
    $notification = db()->fetchOne(
        'SELECT * FROM ' . DB_PREFIX . 'shop_payment_notifications WHERE notify_hash = ?',
        [$hash]
    );
    if ($notification !== null && (int) $notification['processed'] === 1) {
        return shopPaymentHandledNotification($notification);
    }

    if ($notification === null) {
        try {
            $notificationId = (int) db()->insert('shop_payment_notifications', [
                'gateway' => $gateway,
                'notify_hash' => $hash,
                'received_at' => time(),
                'processed' => 0,
                'process_note' => '',
            ]);
        } catch (Throwable $e) {
            // 并发收到同一通知：唯一索引只准一条落库，随后读取赢家结果。
            $notification = db()->fetchOne(
                'SELECT * FROM ' . DB_PREFIX . 'shop_payment_notifications WHERE notify_hash = ?',
                [$hash]
            );
            if ($notification === null) {
                error_log('[shop] payment notification insert failed: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'shop_err_order_failed'];
            }
            if ((int) $notification['processed'] === 1) {
                return shopPaymentHandledNotification($notification);
            }
            $notificationId = (int) $notification['id'];
        }
    } else {
        $notificationId = (int) $notification['id'];
    }

    // 默认值明确为未验签；没有适配器时不可能误判成功。
    $verified = function_exists('apply_filters')
        ? apply_filters('shop_payment_verify', ['verified' => false], $gateway, $rawBody, $context)
        : ['verified' => false];
    $normalized = shopPaymentNormalizeVerified($verified);
    if (!$normalized['ok']) {
        db()->update('shop_payment_notifications', [
            'processed' => 1,
            'process_note' => 'error:' . $normalized['error'],
        ], 'id = ?', [$notificationId]);
        return ['ok' => false, 'error' => $normalized['error']];
    }

    $order = db()->fetchOne(
        'SELECT id, currency, amount_total FROM ' . DB_PREFIX . 'shop_orders WHERE order_no = ?',
        [$normalized['order_no']]
    );
    $error = '';
    if ($order === null) {
        $error = 'shop_err_order_not_found';
    } elseif ((string) $order['currency'] !== $normalized['currency']) {
        $error = 'shop_err_payment_currency';
    } else {
        try {
            $expectedCents = shopMoneyToCents((string) $order['amount_total']);
        } catch (Throwable $e) {
            $expectedCents = -1;
        }
        if ($expectedCents !== $normalized['amount_cents']) {
            $error = 'shop_err_payment_amount_mismatch';
        }
    }
    if ($error !== '') {
        db()->update('shop_payment_notifications', [
            'processed' => 1,
            'process_note' => 'error:' . $error,
        ], 'id = ?', [$notificationId]);
        return ['ok' => false, 'error' => $error];
    }

    $paid = shopOrderMarkPaid(
        (int) $order['id'],
        $normalized['trade_no'],
        $gateway,
        $rawBody
    );
    if (!$paid['ok']) {
        // 数据库/锁异常保留 processed=0，支付平台重试同一正文时仍可再次处理。
        if ($paid['error'] !== 'shop_err_order_failed') {
            db()->update('shop_payment_notifications', [
                'processed' => 1,
                'process_note' => 'error:' . $paid['error'],
            ], 'id = ?', [$notificationId]);
        }
        return $paid;
    }

    db()->update('shop_payment_notifications', [
        'processed' => 1,
        'process_note' => 'ok:' . (int) $order['id'],
    ], 'id = ?', [$notificationId]);

    return [
        'ok' => true,
        'error' => '',
        'idempotent' => (bool) ($paid['idempotent'] ?? false),
        'order_id' => (int) $order['id'],
    ];
}
