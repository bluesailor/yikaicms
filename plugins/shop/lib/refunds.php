<?php
/**
 * 商城退款（M2-c 人工流程）：独立状态机，与订单/支付分离（立项 §四红线）。
 *
 *   requested（已登记）→ confirmed（确认退款）/ rejected（拒绝）
 * 可退额度扣除已确认及处理中退款；同订单申请和处理在订单行锁下串行。
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

/** 排除当前申请只供确认该申请时使用；历史超额/非法金额一律停止新增退款。 */
function shopRefundableCents(int $orderId, int $excludeRefundId = 0): int
{
    $order = db()->fetchOne(
        'SELECT status, paid_at, amount_total FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?',
        [$orderId]
    );
    if ($order === null || (int) $order['paid_at'] <= 0 || (string) $order['status'] === 'closed') {
        return 0;
    }
    try {
        $available = shopMoneyToCents((string) $order['amount_total']);
        foreach (db()->fetchAll(
            'SELECT amount FROM ' . DB_PREFIX . 'shop_refunds WHERE order_id = ? AND status IN (?, ?) AND id <> ?',
            [$orderId, 'requested', 'confirmed', $excludeRefundId]
        ) as $refund) {
            $reserved = shopMoneyToCents((string) $refund['amount']);
            if ($reserved <= 0 || $reserved > $available) {
                return 0;
            }
            $available -= $reserved;
        }
        return max(0, $available);
    } catch (Throwable $e) {
        return 0;
    }
}

/** 调用方已开启事务；SQLite 在任何快照读取前取得写锁。 */
function shopRefundLockOrder(int $orderId): void
{
    if (db()->isSqlite()) {
        db()->execute('UPDATE ' . DB_PREFIX . 'shop_orders SET id = id WHERE id = ?', [$orderId]);
        return;
    }
    db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'shop_orders WHERE id = ? FOR UPDATE', [$orderId]);
}

/** 商家登记退款（requested）。@return array{ok:bool,error:string} */
function shopRefundCreate(int $orderId, string $amountDecimal, string $reason, int $adminId): array
{
    shopEnsureSchema();
    try {
        $amountCents = shopMoneyToCents($amountDecimal);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'shop_err_price'];
    }
    if ($amountCents <= 0) {
        return ['ok' => false, 'error' => 'shop_err_refund_amount'];
    }

    db()->beginTransaction();
    try {
        shopRefundLockOrder($orderId);
        $maxCents = shopRefundableCents($orderId);
        if ($amountCents > $maxCents) {
            db()->rollBack();
            // 未收款 / 已关闭 / 额度已用完：说明「不能退」，而不是笼统的「金额不对」
            return ['ok' => false, 'error' => $maxCents <= 0 ? 'shop_err_refund_not_allowed' : 'shop_err_refund_amount'];
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
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('[shop] refund create failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'shop_err_order_failed'];
    }
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
    db()->beginTransaction();
    try {
        $orderId = (int) $refund['order_id'];
        shopRefundLockOrder($orderId);
        $refund = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_refunds WHERE id = ? AND order_id = ?', [$refundId, $orderId]);
        if ($refund === null) {
            db()->rollBack();
            return ['ok' => false, 'error' => 'shop_err_refund_not_found'];
        }
        if ((string) $refund['status'] !== 'requested') {
            db()->rollBack();
            return (string) $refund['status'] === $to
                ? ['ok' => true, 'error' => '', 'idempotent' => true]
                : ['ok' => false, 'error' => 'shop_err_refund_state'];
        }
        if ($to === 'confirmed') {
            $amount = shopMoneyToCents((string) $refund['amount']);
            if ($amount <= 0 || $amount > shopRefundableCents($orderId, $refundId)) {
                db()->rollBack();
                return ['ok' => false, 'error' => 'shop_err_refund_amount'];
            }
        }
        $updated = db()->update('shop_refunds', [
            'status' => $to,
            'admin_note' => mb_substr($adminNote, 0, 500),
            'admin_id' => $adminId,
            'handled_at' => time(),
        ], 'id = ? AND status = ?', [$refundId, 'requested']);
        if ($updated !== 1) {
            throw new RuntimeException('shop refund state changed concurrently');
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('[shop] refund update failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'shop_err_order_failed'];
    }
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
