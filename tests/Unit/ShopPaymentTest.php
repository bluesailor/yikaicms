<?php
/** 商城在线支付通知（M2-a）：验签契约、精确金额与真实 SQLite 幂等闭环。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopPaymentTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedActions = [];
    /** @var array<string,mixed> */
    private array $savedFilters = [];

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/hooks.php';
        require_once ROOT_PATH . '/plugins/shop/lib/payments.php';
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
        $this->savedFilters = $GLOBALS['ik_filters'] ?? [];
        $GLOBALS['ik_actions'] = [];
        $GLOBALS['ik_filters'] = [];

        foreach (['shop_payment_notifications', 'shop_refunds', 'shop_payments',
                  'shop_order_items', 'shop_orders', 'shop_products'] as $table) {
            db()->delete($table, '1 = 1');
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['ik_actions'] = $this->savedActions;
        $GLOBALS['ik_filters'] = $this->savedFilters;
    }

    public function testGatewayAndHashBoundaries(): void
    {
        $this->assertTrue(shopPaymentGatewayValid('wechat_pay'));
        $this->assertTrue(shopPaymentGatewayValid('a1-b'));
        $this->assertFalse(shopPaymentGatewayValid('Wechat'));
        $this->assertFalse(shopPaymentGatewayValid('../pay'));
        $this->assertFalse(shopPaymentGatewayValid(str_repeat('a', 21)));
        $this->assertSame(shopPaymentNotifyHash('a', '{}'), shopPaymentNotifyHash('a', '{}'));
        $this->assertNotSame(shopPaymentNotifyHash('a', '{}'), shopPaymentNotifyHash('b', '{}'));
    }

    public function testVerifiedPayloadRequiresExactStringsAndClosedVocabulary(): void
    {
        $valid = [
            'verified' => true,
            'status' => 'succeeded',
            'order_no' => '202609220101010001',
            'trade_no' => 'wx:trade-1',
            'amount' => '19.90',
            'currency' => 'cny',
        ];
        $normalized = shopPaymentNormalizeVerified($valid);
        $this->assertTrue($normalized['ok']);
        $this->assertSame(1990, $normalized['amount_cents']);
        $this->assertSame('CNY', $normalized['currency']);

        foreach ([
            ['verified' => false],
            array_replace($valid, ['status' => 'pending']),
            array_replace($valid, ['amount' => 19.90]),
            array_replace($valid, ['amount' => '0.00']),
            array_replace($valid, ['trade_no' => '../bad']),
            array_replace($valid, ['currency' => 'CN']),
        ] as $bad) {
            $this->assertFalse(shopPaymentNormalizeVerified($bad)['ok']);
        }
    }

    public function testVerifiedNotificationMarksOrderPaidOnlyOnce(): void
    {
        $orderId = $this->seedPendingOrder('202609220101010001', '19.90');
        $raw = '{"signed":"payload-1"}';
        add_filter('shop_payment_verify', static function (mixed $result, string $gateway): mixed {
            if ($gateway !== 'testpay') {
                return $result;
            }
            return [
                'verified' => true,
                'status' => 'succeeded',
                'order_no' => '202609220101010001',
                'trade_no' => 'trade-001',
                'amount' => '19.90',
                'currency' => 'CNY',
            ];
        });
        $paidEvents = 0;
        add_action('shop_order_paid', static function () use (&$paidEvents): void {
            $paidEvents++;
        });

        $first = shopPaymentHandleNotification('testpay', $raw);
        $second = shopPaymentHandleNotification('testpay', $raw);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame(1, $paidEvents);
        $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM shop_payment_notifications'));
        $this->assertSame('awaiting_ship', db()->fetchColumn('SELECT status FROM shop_orders WHERE id = ?', [$orderId]));
        $payment = db()->fetchOne('SELECT * FROM shop_payments WHERE order_id = ?', [$orderId]);
        $this->assertSame('succeeded', $payment['status']);
        $this->assertSame('testpay', $payment['gateway']);
        $this->assertSame('trade-001', $payment['gateway_trade_no']);
        $this->assertSame($raw, $payment['raw_notify']);
    }

    public function testAmountMismatchIsTerminalAndCannotAdvanceOrder(): void
    {
        $orderId = $this->seedPendingOrder('202609220101010002', '19.90');
        add_filter('shop_payment_verify', static fn(): array => [
            'verified' => true,
            'status' => 'succeeded',
            'order_no' => '202609220101010002',
            'trade_no' => 'trade-002',
            'amount' => '19.89',
            'currency' => 'CNY',
        ]);

        $result = shopPaymentHandleNotification('testpay', '{"signed":"payload-2"}');

        $this->assertFalse($result['ok']);
        $this->assertSame('shop_err_payment_amount_mismatch', $result['error']);
        $this->assertSame('pending_payment', db()->fetchColumn('SELECT status FROM shop_orders WHERE id = ?', [$orderId]));
        $this->assertSame(1, (int) db()->fetchColumn('SELECT processed FROM shop_payment_notifications'));
    }

    public function testNoVerifierAlwaysRejects(): void
    {
        $this->seedPendingOrder('202609220101010003', '19.90');
        $result = shopPaymentHandleNotification('unconfigured', '{"status":"succeeded"}');
        $this->assertFalse($result['ok']);
        $this->assertSame('shop_err_payment_unverified', $result['error']);
    }

    private function seedPendingOrder(string $orderNo, string $amount): int
    {
        $now = time();
        $orderId = (int) db()->insert('shop_orders', [
            'order_no' => $orderNo,
            'member_id' => 0,
            'lang' => 'zh-CN',
            'status' => 'pending_payment',
            'currency' => 'CNY',
            'amount_goods' => $amount,
            'amount_shipping' => '0.00',
            'amount_total' => $amount,
            'contact_json' => '{}',
            'address_json' => '{}',
            'expire_at' => $now + 1800,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        db()->insert('shop_payments', [
            'order_id' => $orderId,
            'gateway' => 'offline',
            'status' => 'created',
            'amount' => $amount,
            'created_at' => $now,
        ]);
        return $orderId;
    }
}
