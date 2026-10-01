<?php
/**
 * 商城交易一致性（2.0.3）：退款额度扣除处理中与已确认的退款、状态推进只成功一次、
 * 结算请求可安全重放。真实 SQLite 库上跑，与 ShopPaymentTest 同一套建表。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopTransactionHardeningTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedActions = [];

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/hooks.php';
        require_once ROOT_PATH . '/plugins/shop/lib/payments.php';
        require_once ROOT_PATH . '/plugins/shop/lib/refunds.php';
        require_once ROOT_PATH . '/plugins/shop/lib/orders.php';
        db()->execute(
            'CREATE TABLE IF NOT EXISTS settings ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, value TEXT, '
            . '`group` TEXT, name TEXT, tip TEXT)'
        );
        settingModel()->clearCache();
        shopEnsureSchema();
    }

    protected function setUp(): void
    {
        $this->savedActions = $GLOBALS['ik_actions'] ?? [];
        $GLOBALS['ik_actions'] = [];
        foreach (['shop_checkout_requests', 'shop_refunds', 'shop_payments', 'shop_order_items', 'shop_orders', 'shop_products'] as $table) {
            db()->delete($table, '1 = 1');
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['ik_actions'] = $this->savedActions;
    }

    public function testSchemaHasTheCheckoutRequestTable(): void
    {
        $this->assertSame(3, shopSchemaVersion());
        $this->assertNotFalse(db()->fetchAll('SELECT * FROM shop_checkout_requests'));
    }

    public function testPendingAndConfirmedRefundsShareOneBudget(): void
    {
        $orderId = $this->seedOrder('202610010000000001', '100.00', 'awaiting_ship', true);

        $this->assertTrue(shopRefundCreate($orderId, '60.00', 'part', 1)['ok']);
        // 60 处理中：只剩 40
        $this->assertSame('shop_err_refund_amount', shopRefundCreate($orderId, '50.00', 'too much', 1)['error']);
        $this->assertTrue(shopRefundCreate($orderId, '40.00', 'rest', 1)['ok']);
        // 额度用完：明确「不能退」
        $this->assertSame('shop_err_refund_not_allowed', shopRefundCreate($orderId, '0.01', 'none left', 1)['error']);

        [$sixty, $forty] = array_column(db()->fetchAll('SELECT id FROM shop_refunds WHERE order_id = ? ORDER BY id', [$orderId]), 'id');
        // 拒绝释放占用
        $this->assertTrue(shopRefundUpdate((int) $sixty, 'rejected', 'no', 1)['ok']);
        $this->assertSame(6000, shopRefundableCents($orderId));
        // 确认不重复扣额度；重复确认幂等；已确认的不能再改成拒绝
        $this->assertTrue(shopRefundUpdate((int) $forty, 'confirmed', 'ok', 1)['ok']);
        $this->assertSame(6000, shopRefundableCents($orderId));
        $this->assertTrue(shopRefundUpdate((int) $forty, 'confirmed', 'again', 1)['idempotent'] ?? false);
        $this->assertSame('shop_err_refund_state', shopRefundUpdate((int) $forty, 'rejected', 'flip', 1)['error']);
    }

    public function testRefundsNeedAPaidOpenOrder(): void
    {
        $unpaid = $this->seedOrder('202610010000000002', '10.00', 'pending_payment', false);
        $this->assertSame('shop_err_refund_not_allowed', shopRefundCreate($unpaid, '1.00', 'x', 1)['error']);
        $this->assertSame('shop_err_refund_amount', shopRefundCreate($unpaid, '0', 'x', 1)['error']);
    }

    public function testHistoricOverRefundStopsNewRefundsButCanBeRejected(): void
    {
        $orderId = $this->seedOrder('202610010000000003', '10.00', 'completed', true);
        // 旧版本允许过的超额申请（20 > 10）
        db()->insert('shop_refunds', ['order_id' => $orderId, 'payment_id' => 0, 'amount' => '20.00', 'status' => 'requested',
            'reason' => 'legacy', 'admin_id' => 1, 'created_at' => time()]);
        $legacy = (int) db()->fetchOne('SELECT id FROM shop_refunds WHERE order_id = ?', [$orderId])['id'];
        $this->assertSame(0, shopRefundableCents($orderId));
        $this->assertSame('shop_err_refund_amount', shopRefundUpdate($legacy, 'confirmed', 'x', 1)['error']);
        $this->assertTrue(shopRefundUpdate($legacy, 'rejected', 'cleanup', 1)['ok']);
        $this->assertSame(1000, shopRefundableCents($orderId));
    }

    public function testClosingAnOrderHappensOnceAndOnlyFromPendingPayment(): void
    {
        $pending = $this->seedOrder('202610010000000004', '10.00', 'pending_payment', false);
        $this->assertTrue(shopOrderClose($pending, 'timeout')['ok']);
        $again = shopOrderClose($pending, 'timeout');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['idempotent'] ?? false, '第二次关闭只确认结果，不再回补库存');

        $paid = $this->seedOrder('202610010000000005', '10.00', 'awaiting_ship', true);
        $this->assertFalse(shopOrderClose($paid, 'nope')['ok']);
        $this->assertSame('awaiting_ship', db()->fetchOne('SELECT status FROM shop_orders WHERE id = ?', [$paid])['status']);
    }

    public function testCheckoutRequestReplayReturnsTheSameOrderOnlyToItsOwner(): void
    {
        $orderId = $this->seedOrder('202610010000000006', '10.00', 'pending_payment', false);
        $key = str_repeat('a', 32);
        $owner = str_repeat('b', 64);
        $payload = str_repeat('c', 64);
        db()->insert('shop_checkout_requests', ['request_key' => $key, 'owner_hash' => $owner, 'payload_hash' => $payload,
            'order_id' => $orderId, 'created_at' => time()]);

        $replay = shopCheckoutReplay($key, $owner, $payload);
        $this->assertTrue($replay['ok'] ?? false);
        $this->assertSame('202610010000000006', $replay['order_no'] ?? '');
        $this->assertTrue($replay['idempotent'] ?? false);

        $this->assertSame('shop_err_checkout_changed', shopCheckoutReplay($key, str_repeat('d', 64), $payload)['error'] ?? '');
        $this->assertSame('shop_err_checkout_changed', shopCheckoutReplay($key, $owner, str_repeat('e', 64))['error'] ?? '');
        $this->assertNull(shopCheckoutReplay(str_repeat('f', 32), $owner, $payload));

        // 唯一键：同一请求号不能落两次
        $this->expectException(\Throwable::class);
        db()->insert('shop_checkout_requests', ['request_key' => $key, 'owner_hash' => $owner, 'payload_hash' => $payload,
            'order_id' => 0, 'created_at' => time()]);
    }

    private function seedOrder(string $orderNo, string $amount, string $status, bool $paid): int
    {
        $now = time();
        return (int) db()->insert('shop_orders', [
            'order_no' => $orderNo, 'member_id' => 0, 'lang' => 'zh-CN', 'status' => $status, 'currency' => 'CNY',
            'amount_goods' => $amount, 'amount_shipping' => '0.00', 'amount_total' => $amount,
            'contact_json' => '{}', 'address_json' => '{}', 'expire_at' => $now + 1800,
            'paid_at' => $paid ? $now : 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
