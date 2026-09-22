<?php
/**
 * 商城退款（M2-c 人工流程）：独立状态机，与订单/支付分离（立项 §四红线）。
 *
 *   requested（已登记）→ confirmed（确认退款）/ rejected（拒绝）
 * 可退条件：订单已收款（paid_at > 0）且未关闭；金额 ∈ (0, 订单实付总额]。
 * 确认退款**不**自动改订单状态——退款是支付维度的事件，订单流转由商家另行
 * 决定（照立项「不用一个 status 包办」的原则，不替商家做这个决定）。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/tables.php';

/** @return list<string> 退款合法状态 */
function shopRefundStatuses(): array
{
    return ['requested', 'confirmed', 'rejected'];
}

/** 订单可退金额上限（分）＝ 订单总额。订单不存在/未收款/已关闭返回 0。 */
function shopRefundableCents(int $orderId): int
{
    $order = db()->fetchOne(
        'SELECT status, paid_at, amount_total FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?',
        [$orderId]
    );
    if ($order === null || (int) $order['paid_at'] <= 0 || (string) $order['status'] === 'closed') {
        return 0;
    }
    try {
        return shopMoneyToCents((string) $order['amount_total']);
    } catch (Throwable $e) {
        return 0;
    }
}

/** 商家登记退款（requested）。@return array{ok:bool,error:string} */
function shopRefundCreate(int $orderId, string $amountDecimal, string $reason, int $adminId): array
{
    shopEnsureSchema();
    $maxCents = shopRefundableCents($orderId);
    if ($maxCents <= 0) {
        return ['ok' => false, 'error' => 'shop_err_refund_not_allowed'];
    }
    try {
        $amountCents = shopMoneyToCents($amountDecimal);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'shop_err_price'];
    }
    if ($amountCents <= 0 || $amountCents > $maxCents) {
        return ['ok' => false, 'error' => 'shop_err_refund_amount'];
    }

    $payment = db()->fetchOne(
        'SELECT id FROM ' . DB_PREFIX . 'shop_payments WHERE order_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
        [$orderId, 'succeeded']
    );
    db()->insert('shop_refunds', [
        'order_id' => $orderId,
        'payment_id' => $payment !== null ? (int) $payment['id'] : 0,
        'amount' => shopCentsToDecimal($amountCents),
        'status' => 'requested',
        'reason' => mb_substr($reason, 0, 500),
        'admin_id' => $adminId,
        'created_at' => time(),
    ]);
    do_action('data_changed');

    return ['ok' => true, 'error' => ''];
}

/**
 * 确认 / 拒绝退款。仅 requested 可推进；重复处理幂等返回成功。
 * @return array{ok:bool,error:string,idempotent?:bool}
 */
function shopRefundUpdate(int $refundId, string $to, string $adminNote, int $adminId): array
{
    shopEnsureSchema();
    if (!in_array($to, ['confirmed', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'shop_err_op'];
    }
    $refund = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_refunds WHERE id = ?', [$refundId]);
    if ($refund === null) {
        return ['ok' => false, 'error' => 'shop_err_refund_not_found'];
    }
    if ((string) $refund['status'] !== 'requested') {
        return ['ok' => true, 'error' => '', 'idempotent' => true];
    }
    db()->update('shop_refunds', [
        'status' => $to,
        'admin_note' => mb_substr($adminNote, 0, 500),
        'admin_id' => $adminId,
        'handled_at' => time(),
    ], 'id = ? AND status = ?', [$refundId, 'requested']);
    do_action('data_changed');

    return ['ok' => true, 'error' => ''];
}

/** 订单的全部退款记录。@return list<array<string,mixed>> */
function shopRefundsByOrder(int $orderId): array
{
    return db()->fetchAll(
        'SELECT * FROM ' . DB_PREFIX . 'shop_refunds WHERE order_id = ? ORDER BY id DESC',
        [$orderId]
    );
}
