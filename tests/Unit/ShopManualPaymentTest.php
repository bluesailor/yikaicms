<?php
/** 商城人工收款资料：字段门禁、安全图片 URL 与启用状态。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopManualPaymentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/payment-methods.php';
    }

    public function testReservedGatewayIdsCoverPlannedAdapters(): void
    {
        $this->assertSame(
            ['offline', 'alipay', 'wechat_pay', 'stripe', 'paypal', 'wise'],
            shopReservedPaymentGatewayIds()
        );
    }

    public function testNormalizesPersonalCompanyAndQrMethods(): void
    {
        $result = shopNormalizeManualPaymentMethods([
            [
                'enabled' => '1',
                'subject_type' => 'company',
                'label' => ' 对公转账 ',
                'payee' => '示例有限公司',
                'institution' => '示例银行',
                'account' => ' 622200001 ',
                'qr_image' => '/uploads/pay/company.png',
                'instructions' => "工作日确认\r\n请备注订单号",
            ],
            [
                'enabled' => '0',
                'subject_type' => 'personal',
                'label' => '个人收款码',
                'qr_image' => 'https://static.example.test/pay.png',
            ],
            ['label' => '待删除', 'account' => '123', 'remove' => '1'],
            ['label' => '', 'account' => ''],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['methods']);
        $this->assertSame(1, $result['methods'][0]['enabled']);
        $this->assertSame('company', $result['methods'][0]['subject_type']);
        $this->assertSame('对公转账', $result['methods'][0]['label']);
        $this->assertSame('622200001', $result['methods'][0]['account']);
        $this->assertSame("工作日确认\n请备注订单号", $result['methods'][0]['instructions']);
        $this->assertSame(0, $result['methods'][1]['enabled']);
        $this->assertJson($result['value']);
    }

    public function testRejectsUnsafeOrIncompleteMethods(): void
    {
        foreach ([
            [['subject_type' => 'other', 'label' => 'x', 'account' => '1']],
            [['subject_type' => 'personal', 'label' => '', 'account' => '1']],
            [['subject_type' => 'personal', 'label' => 'x', 'qr_image' => 'javascript:alert(1)']],
            [['subject_type' => 'personal', 'label' => 'x']],
            [['subject_type' => 'personal', 'label' => "x\0", 'account' => '1']],
        ] as $input) {
            $this->assertFalse(shopNormalizeManualPaymentMethods($input)['ok']);
        }
    }

    public function testLimitsMethodsToTen(): void
    {
        $rows = [];
        for ($i = 0; $i < 11; $i++) {
            $rows[] = ['subject_type' => 'personal', 'label' => 'Method ' . $i, 'account' => (string) $i];
        }
        $this->assertFalse(shopNormalizeManualPaymentMethods($rows)['ok']);
    }
}
