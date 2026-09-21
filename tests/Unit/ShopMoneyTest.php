<?php
/**
 * 商城金额换算边界测试（M0-2，立项报告 §五红线的守门测试）。
 *
 * 重点回归立项 v1 的 sscanf 方案两处实测错误：
 *   '10.5' 一位小数少算 45 分；'-3.20' 负号处理错（-280 而非 -320）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShopMoneyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/money.php';
    }

    /** @return list<array{0:string,1:int}> */
    public static function validCases(): array
    {
        return [
            ['10.50', 1050],
            ['10.5', 1050],       // 一位小数：补零而非拼尾（sscanf 方案的坑）
            ['-3.20', -320],      // 负数：符号单独处理（sscanf 方案的坑）
            ['+3.20', 320],
            ['0.05', 5],
            ['0', 0],
            ['0.00', 0],
            ['1', 100],
            ['99999999.99', 9999999999],   // decimal(10,2) 上限
            ['10.500', 1050],     // SQLite 可能多带尾零：第三位全零允许
            ['-0.10', -10],
            [' 7.25 ', 725],      // 容忍首尾空白
        ];
    }

    /** @dataProvider validCases */
    public function testToCents(string $decimal, int $expected): void
    {
        $this->assertSame($expected, shopMoneyToCents($decimal), "input: {$decimal}");
    }

    /** @return list<array{0:string}> */
    public static function invalidCases(): array
    {
        return array_map(
            static fn(string $v): array => [$v],
            ['', 'abc', '1.234', '-1.235', '.5', '1.', '--1', '1..2', '1,5', '12a.30', '1e3', '-']
        );
    }

    /** @dataProvider invalidCases */
    public function testToCentsRejects(string $decimal): void
    {
        $this->expectException(InvalidArgumentException::class);
        shopMoneyToCents($decimal);
    }

    public function testCentsToDecimalRoundTrip(): void
    {
        foreach ([1050, -320, 5, 0, 9999999999, -1] as $cents) {
            $this->assertSame(
                $cents,
                shopMoneyToCents(shopCentsToDecimal($cents)),
                "round trip: {$cents}"
            );
        }
        $this->assertSame('-3.20', shopCentsToDecimal(-320));
        $this->assertSame('0.05', shopCentsToDecimal(5));
        $this->assertSame('10.00', shopCentsToDecimal(1000));
    }

    public function testMultiplyAndSum(): void
    {
        $this->assertSame(3150, shopMoneyMultiply(shopMoneyToCents('10.50'), 3));
        $this->assertSame(0, shopMoneyMultiply(1050, 0));
        $this->assertSame(-960, shopMoneyMultiply(shopMoneyToCents('-3.20'), 3));
        $this->expectException(InvalidArgumentException::class);
        shopMoneyMultiply(1050, -1);
    }

    public function testSumAddsPartsAsInts(): void
    {
        $this->assertSame(1405, shopMoneySum([1050, -320, 675]));
        $this->assertSame(0, shopMoneySum([]));
        $this->expectException(InvalidArgumentException::class);
        shopMoneySum([1050, 1.5]);
    }

    /**
     * 评审 P1-2 的回归：超长数字串必须在 (int) 强转**之前**被拒，
     * 否则 TypeError 逃出正常的售价错误提示（评审实测 PHP_INT_MAX×2 即触发）。
     */
    public function testToCentsRejectsOversizedDigitStringsBeforeCast(): void
    {
        foreach ([
            '99999999999999999999',            // 20 位整数
            (string) PHP_INT_MAX . '0',         // PHP_INT_MAX × 10
            '1.' . str_repeat('0', 30) . '1',  // 超长小数（且第三位后非零）
        ] as $bad) {
            $e = null;
            try {
                shopMoneyToCents($bad);
            } catch (InvalidArgumentException $e) {
                // 期望路径
            } catch (\Throwable $other) {
                $this->fail("oversized input '{$bad}' must throw InvalidArgumentException, got " . get_class($other));
            }
            $this->assertNotNull($e, "oversized input '{$bad}' must be rejected");
        }
        // 12 位以内是可解析的（业务上限由领域函数另收）
        $this->assertSame(10000000000000, shopMoneyToCents('100000000000'));
    }

    /** 售价入口：只有正数且 ≤ decimal(10,2) 上限才通过；负数/零/超限全部拒绝。 */
    public function testSalePriceValidatorBounds(): void
    {
        $this->assertSame(1050, shopValidSalePriceCents('10.50'));
        $this->assertSame(1, shopValidSalePriceCents('0.01'));
        $this->assertSame(shopMoneyMaxCents(), shopValidSalePriceCents('99999999.99'));

        foreach (['0', '0.00', '-1.00', '-0.01', '100000000.00', '99999999999999999999', 'abc', '1.234'] as $bad) {
            $this->assertNull(shopValidSalePriceCents($bad), "sale price '{$bad}' must be rejected");
        }
    }

    /** 库存入口：非负整数，超长串在转换前拒绝（防 TypeError）。 */
    public function testStockValidatorBounds(): void
    {
        $this->assertSame(0, shopValidStock('0'));
        $this->assertSame(5, shopValidStock('5'));
        $this->assertSame(9999999999, shopValidStock('9999999999'));

        foreach (['', '-1', '1.5', 'abc', '99999999999', (string) PHP_INT_MAX . '0'] as $bad) {
            $this->assertNull(shopValidStock($bad), "stock '{$bad}' must be rejected");
        }
    }

    /** 多语言共享键：翻译组优先、未翻译退回自身（评审 §4-3 定案的行为锁）。 */
    public function testCanonicalProductIdResolvesTranslationGroup(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/sales.php';
        $this->assertSame(7, shopCanonicalProductId(['id' => 9, 'translation_group_id' => 7]));
        $this->assertSame(9, shopCanonicalProductId(['id' => 9, 'translation_group_id' => 0]));
        $this->assertSame(9, shopCanonicalProductId(['id' => 9]));   // 无组字段：退回自身
    }
}
