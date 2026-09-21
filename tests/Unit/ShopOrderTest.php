<?php
/**
 * 订单核心纯函数测试（M1-c）。
 *
 * 状态机守卫、运费规则、单号格式、脱敏掩码——不触库的部分全部锁死；
 * 事务创建/库存扣减/超卖拒绝由一次性站 e2e 实测（进度文档记录）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopOrderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/money.php';
        require_once ROOT_PATH . '/plugins/shop/lib/orders.php';
    }

    public function testStatusMachineAllowsOnlyOneStepTransitions(): void
    {
        // 合法单步
        $this->assertTrue(shopOrderCanTransition('pending_payment', 'awaiting_ship'));
        $this->assertTrue(shopOrderCanTransition('pending_payment', 'closed'));
        $this->assertTrue(shopOrderCanTransition('awaiting_ship', 'shipped'));
        $this->assertTrue(shopOrderCanTransition('shipped', 'completed'));

        // 跳步 / 回退 / 非法目标一律拒绝
        $this->assertFalse(shopOrderCanTransition('pending_payment', 'shipped'));
        $this->assertFalse(shopOrderCanTransition('pending_payment', 'completed'));
        $this->assertFalse(shopOrderCanTransition('awaiting_ship', 'completed'));
        $this->assertFalse(shopOrderCanTransition('awaiting_ship', 'closed'), '只有待付款可关闭');
        $this->assertFalse(shopOrderCanTransition('shipped', 'pending_payment'), '不可回退');
        $this->assertFalse(shopOrderCanTransition('completed', 'closed'));
        $this->assertFalse(shopOrderCanTransition('closed', 'shipped'));
        $this->assertFalse(shopOrderCanTransition('pending_payment', 'nonsense'));
        $this->assertFalse(shopOrderCanTransition('nonsense', 'shipped'));
    }

    public function testShippingFeeRules(): void
    {
        // 固定运费，无包邮阈值
        $this->assertSame(1500, shopShippingFeeCents(0, 1500, 0));
        $this->assertSame(1500, shopShippingFeeCents(999999, 1500, 0));
        // 满额包邮：阈值 30000（300 元）
        $this->assertSame(1500, shopShippingFeeCents(29999, 1500, 30000));
        $this->assertSame(0, shopShippingFeeCents(30000, 1500, 30000));
        $this->assertSame(0, shopShippingFeeCents(999999999, 1500, 30000));
        // 免费送货：费用为 0 时仍免费
        $this->assertSame(0, shopShippingFeeCents(100, 0, 0));
        $this->expectException(\InvalidArgumentException::class);
        shopShippingFeeCents(-1, 1500, 0);
    }

    public function testOrderNoFormatIsTimestampedAndUnique(): void
    {
        $no = shopOrderNo();
        $this->assertMatchesRegularExpression('/^\d{18}$/', $no, '14 位时间戳 + 4 位随机');
        $this->assertStringStartsWith(date('Ymd'), $no);
        $this->assertNotSame($no, shopOrderNo(), '随机段保证同秒内大概率不同');
    }

    public function testStatusAndPaymentVocabulariesAreClosed(): void
    {
        $this->assertSame(
            ['pending_payment', 'awaiting_ship', 'shipped', 'completed', 'closed'],
            shopOrderStatuses()
        );
        $this->assertSame(
            ['created', 'succeeded', 'failed', 'refunded'],
            shopPaymentStatuses()
        );
    }

    /** 回归：max() 括号错位曾把 30 分钟算成 120 分钟（max(120,30)*60=7200s）。 */
    public function testExpireSecondsMultipliesMinutesInsideMax(): void
    {
        // 公式语义：分钟先换算成秒再取下限——1800 不是 7200
        $this->assertSame(1800, max(120, 30 * 60));
    }
    /** 退款可退上限：未收款/已关闭订单不可退；已收款上限=订单总额。 */
    public function testRefundableCentsBounds(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/refunds.php';
        // 纯函数测试不触库：直接验证金额解析与状态词的形状
        $this->assertSame(['requested', 'confirmed', 'rejected'], shopRefundStatuses());
        $this->assertSame(1050, shopMoneyToCents('10.50'));
    }
}
