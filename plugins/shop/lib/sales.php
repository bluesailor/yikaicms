<?php
/**
 * 商城销售配置数据层（M0 加固：评审 P2-1，筛选与分页收进 SQL）。
 *
 * admin.php 不再自己拼业务查询；「按上架状态筛选」必须在 WHERE 里做，
 * 否则分页按全量计数、页内再剔除，会出现「第一页空、后面页有货」的错位。
 *
 * 多语言库存归属（评审 §4-3 的既定决策，详见 progress 文档）：
 * 销售配置挂在**翻译组**上——shop_products.product_id 存的是 canonical id
 * （translation_group_id > 0 时取组 id，否则取产品自身 id）。同一商品各语言
 * 版本因此共享 SKU/售价/库存，不会出现几份独立库存；单语言站组 id 常为 0，
 * canonical 即自身，行为不变。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';

/** 产品的销售归属键：翻译组优先，未翻译退回自身 id。 */
function shopCanonicalProductId(array $productRow): int
{
    $groupId = (int) ($productRow['translation_group_id'] ?? 0);

    return $groupId > 0 ? $groupId : (int) ($productRow['id'] ?? 0);
}

/**
 * 分页取「产品 + 销售配置」联表。
 *
 * @param array{keyword?:string,sales?:string} $filters sales: all|on|off
 * @return array{items: list<array<string,mixed>>, total: int}
 */
function shopSalesPage(array $filters, int $limit, int $offset): array
{
    $where = ['p.deleted_at IS NULL'];
    $params = [];

    $keyword = trim((string) ($filters['keyword'] ?? ''));
    if ($keyword !== '') {
        $where[] = '(p.title LIKE ? OR p.model LIKE ?)';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
    }
    // 状态筛选进 WHERE：on=上架；off 含「未启用销售」与「已配置但下架」两种
    $sales = (string) ($filters['sales'] ?? 'all');
    if ($sales === 'on') {
        $where[] = 'sp.status = 1';
    } elseif ($sales === 'off') {
        $where[] = '(sp.product_id IS NULL OR sp.status = 0)';
    }
    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $sqlBase = 'FROM ' . DB_PREFIX . "products p
        LEFT JOIN " . DB_PREFIX . 'shop_products sp
            ON sp.product_id = CASE WHEN p.translation_group_id > 0 THEN p.translation_group_id ELSE p.id END';

    $total = (int) db()->fetchColumn(
        'SELECT COUNT(*) ' . $sqlBase . ' ' . $whereSQL,
        $params
    );
    $items = db()->fetchAll(
        'SELECT p.id, p.lang, p.title, p.model, p.price,
                sp.product_id AS sales_key, sp.sku, sp.price AS sale_price,
                sp.stock, sp.status, sp.sales
            ' . $sqlBase . ' ' . $whereSQL . '
            ORDER BY p.id DESC LIMIT ? OFFSET ?',
        array_merge($params, [$limit, $offset])
    );

    return ['items' => $items, 'total' => $total];
}

/**
 * canonical 键 → 当前语言的展示产品行（前台购物车/结算用）。
 * 优先当前语言；组里没有当前语言行时退回源行（canonical 自身）。找不到返回 null。
 *
 * @return array<string,mixed>|null
 */
function shopResolveProductRow(int $canonicalId): ?array
{
    $lang = function_exists('siteLang') ? siteLang() : 'zh-CN';
    $table = DB_PREFIX . 'products';
    $row = db()->fetchOne(
        "SELECT * FROM {$table}
         WHERE deleted_at IS NULL AND (translation_group_id = ? OR id = ?) AND lang = ?
         ORDER BY (id = ?) DESC LIMIT 1",
        [$canonicalId, $canonicalId, $lang, $canonicalId]
    );
    if ($row === null) {
        $row = db()->fetchOne(
            "SELECT * FROM {$table} WHERE deleted_at IS NULL AND id = ? LIMIT 1",
            [$canonicalId]
        );
    }

    return is_array($row) ? $row : null;
}

/**
 * 某行的有效单价（分）：覆盖价优先，否则产品行价格。
 * @param array<string,mixed> $productRow
 * @param array<string,mixed>|null $salesRow
 */
function shopEffectivePriceCents(array $productRow, ?array $salesRow): int
{
    if ($salesRow !== null) {
        $salePrice = $salesRow['sale_price'] ?? ($salesRow['price'] ?? null);
        if ($salePrice !== null && (string) $salePrice !== '') {
            return shopMoneyToCents((string) $salePrice);
        }
    }

    return shopMoneyToCents((string) ($productRow['price'] ?? '0'));
}
