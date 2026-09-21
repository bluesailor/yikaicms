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
}
