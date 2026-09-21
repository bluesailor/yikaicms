<?php
/**
 * 商城购物车（M1-b）：session 行模型。
 *
 * 设计约束（立项 §五红线）：
 * - 车里**只存 [canonicalId, qty]**——价格/名称/库存永远在渲染与下单时从库里
 *   重算，购物车价格不可信；
 * - 键是翻译组 canonical id（多语言共享库存），不是语言行 id；
 * - 上限：单行数量 ≤ 999 且 ≤ 现库存；行数 ≤ 50（防把 session 当存储灌爆）。
 *
 * 库依赖通过 $lookup 可调用注入（默认走 shopSalesByCanonical），单测可传假货。
 */

declare(strict_types=1);

require_once __DIR__ . '/sales.php';

/** @return array<string, mixed>|null 销售配置行（status/stock/sale_price/sku），不在售返回 null */
function shopCartSalesLookupDefault(int $canonicalId): ?array
{
    $row = db()->fetchOne(
        'SELECT product_id, sku, price AS sale_price, stock, status FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?',
        [$canonicalId]
    );

    return is_array($row) && (int) ($row['status'] ?? 0) === 1 ? $row : null;
}

/** 当前购物车原始行。@return list<array{id:int,qty:int}> */
function shopCartLines(): array
{
    $lines = $_SESSION['shop_cart'] ?? [];
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        if (is_array($line) && (int) ($line['id'] ?? 0) > 0 && (int) ($line['qty'] ?? 0) > 0) {
            $out[] = ['id' => (int) $line['id'], 'qty' => (int) $line['qty']];
        }
    }

    return $out;
}

/** 总件数（用于角标）。 */
function shopCartCount(): int
{
    $total = 0;
    foreach (shopCartLines() as $line) {
        $total += $line['qty'];
    }

    return $total;
}

/**
 * 加入购物车（已有同键行则累加）。返回 [ok, error]；error 为语言键名。
 *
 * @param callable(int): ?array $lookup 键 → 销售配置行（或 null）
 * @return array{ok: bool, error: string}
 */
function shopCartAdd(int $canonicalId, int $qty, ?callable $lookup = null): array
{
    $lookup ??= 'shopCartSalesLookupDefault';
    $fail = static fn(string $key): array => ['ok' => false, 'error' => $key];

    if ($canonicalId <= 0) {
        return $fail('shop_err_product');
    }
    if ($qty < 1 || $qty > 999) {
        return $fail('shop_err_qty');
    }
    $sales = $lookup($canonicalId);
    if ($sales === null) {
        return $fail('shop_err_not_on_sale');
    }

    $lines = shopCartLines();
    $existing = 0;
    foreach ($lines as $line) {
        if ($line['id'] === $canonicalId) {
            $existing = $line['qty'];
            break;
        }
    }
    if ($existing === 0 && count($lines) >= 50) {
        return $fail('shop_err_too_many_lines');
    }
    $nextQty = $existing + $qty;
    if ($nextQty > 999 || $nextQty > (int) $sales['stock']) {
        return $fail('shop_err_out_of_stock');
    }

    shopCartWriteLine($canonicalId, $nextQty);

    return ['ok' => true, 'error' => ''];
}

/**
 * 设置某行数量（qty ≤ 0 即移除该行）。不校验库存上调——购物车只是意向，
 * 库存在下单事务里才是硬约束；这里只防离谱值。
 *
 * @return array{ok: bool, error: string}
 */
function shopCartSetQty(int $canonicalId, int $qty): array
{
    if ($canonicalId <= 0) {
        return ['ok' => false, 'error' => 'shop_err_product'];
    }
    if ($qty < 0 || $qty > 999) {
        return ['ok' => false, 'error' => 'shop_err_qty'];
    }
    shopCartWriteLine($canonicalId, $qty);

    return ['ok' => true, 'error' => ''];
}

function shopCartRemove(int $canonicalId): void
{
    shopCartWriteLine($canonicalId, 0);
}

function shopCartClear(): void
{
    $_SESSION['shop_cart'] = [];
}

/** 内部：写一行（qty ≤ 0 移除），保持行序稳定。 */
function shopCartWriteLine(int $canonicalId, int $qty): void
{
    $lines = shopCartLines();
    $next = [];
    $found = false;
    foreach ($lines as $line) {
        if ($line['id'] === $canonicalId) {
            $found = true;
            if ($qty > 0) {
                $next[] = ['id' => $canonicalId, 'qty' => $qty];
            }
            continue;
        }
        $next[] = $line;
    }
    if (!$found && $qty > 0) {
        $next[] = ['id' => $canonicalId, 'qty' => $qty];
    }
    $_SESSION['shop_cart'] = $next;
}
