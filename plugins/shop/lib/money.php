<?php
/**
 * 商城金额换算（M0-2）。
 *
 * 红线（立项报告 §五）：金额计算禁止浮点中转。存储层是 decimal(10,2)（与全站
 * 一致），PHP 侧一律换算成「整数分」运算，输出再回到两位小数字符串。
 *
 * 为什么不用 sscanf('%d.%d')（立项 v1 的方案，已实测作废）：
 *   '10.5'  → 10*100+5  = 1005（少 45 分）
 *   '-3.20' → -3*100+20 = -280（负号处理错）
 * 且 SQLite 读回的小数位不保证两位。这里改为：剥符号 → 拆整数/小数两部分 →
 * 小数右侧补零到两位（第二位之后只允许全零）→ 拼回。
 *
 * 纯函数：不触库、不依赖站点状态；配套边界测试见 tests/Unit/ShopMoneyTest.php。
 */

declare(strict_types=1);

/**
 * 十进制金额字符串 → 整数分。非法输入抛异常（商城宁可拒单也不能算错钱）。
 *
 * 接受：'10.50' / '10.5' / '10.500'（尾随零允许）/ '0' / '-3.20' / '+3.20'。
 * 拒绝：'' / 'abc' / '1.234'（第三位非零，静默截断等于吞钱）/ '.5' / '1.' / '--1'。
 */
function shopMoneyToCents(string $decimal): int
{
    $value = trim($decimal);
    if ($value === '') {
        throw new InvalidArgumentException('shop money: empty amount');
    }

    $negative = false;
    if ($value[0] === '-' || $value[0] === '+') {
        $negative = $value[0] === '-';
        $value = substr($value, 1);
    }

    // 整数与小数两部分都必须是非空纯数字（'.5'、'1.' 这类半截写法直接拒绝）
    if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
        throw new InvalidArgumentException('shop money: malformed amount "' . $decimal . '"');
    }
    $frac = $m[2] ?? '';
    if (strlen($frac) > 2 && (int) substr($frac, 2) !== 0) {
        // 第三位及以后还有非零数字：换算必然丢钱，拒绝而不是四舍五入
        throw new InvalidArgumentException('shop money: more than 2 decimals "' . $decimal . '"');
    }
    $frac = substr(str_pad($frac, 2, '0', STR_PAD_RIGHT), 0, 2);

    // decimal(10,2) 上限 99999999.99 → 分值远在 64 位 int 范围内，无溢出风险
    $cents = (int) $m[1] * 100 + (int) $frac;

    return $negative ? -$cents : $cents;
}

/** 整数分 → 两位小数字符串（落库/展示用；负数保留符号）。 */
function shopCentsToDecimal(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $abs = abs($cents);

    return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** 分 → 分*数量的金额行小计（整数分运算，溢出校验）。qty 为 0 时结果为 0。 */
function shopMoneyMultiply(int $cents, int $qty): int
{
    if ($qty < 0) {
        throw new InvalidArgumentException('shop money: negative qty');
    }
    $total = $cents * $qty;
    // 溢出回检只在 qty>0 且积非零时成立（qty=0 积恒为 0，回检必然误报）
    if ($qty > 0 && intdiv($total, $qty) !== $cents) {
        throw new InvalidArgumentException('shop money: amount overflow');
    }

    return $total;
}

/** 多笔分值安全求和（64 位溢出会退化成 float，当场拒绝）。@param list<int> $parts */
function shopMoneySum(array $parts): int
{
    $sum = 0;
    foreach ($parts as $part) {
        if (!is_int($part)) {
            throw new InvalidArgumentException('shop money: non-int part');
        }
        $next = $sum + $part;
        if (!is_int($next)) {
            // PHP 整数溢出不抛错而是变 float——金额一旦变 float 就不能再信
            throw new InvalidArgumentException('shop money: sum overflow');
        }
        $sum = $next;
    }

    return $sum;
}
