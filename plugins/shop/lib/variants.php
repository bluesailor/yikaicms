<?php
/** 商城有限 SKU 变体：解析、规范化与库存调整。 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';

function shopVariantId(string $label, string $sku): string
{
    return substr(hash('sha256', mb_strtolower(trim($label)) . "\0" . trim($sku)), 0, 20);
}

/** @return list<array{id:string,label:string,sku:string,price:?string,stock:int}> */
function shopProductVariantsFromJson(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [];
    }
    if (!is_array($decoded) || count($decoded) > 50) {
        return [];
    }
    $variants = [];
    $seen = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            return [];
        }
        $label = trim((string) ($row['label'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        $stock = $row['stock'] ?? null;
        $priceRaw = $row['price'] ?? null;
        $price = $priceRaw === null || $priceRaw === '' ? null : (string) $priceRaw;
        $id = (string) ($row['id'] ?? '');
        if ($label === '' || mb_strlen($label) > 100 || mb_strlen($sku) > 64
            || !is_int($stock) || $stock < 0 || $stock > 2147483647
            || ($price !== null && shopValidSalePriceCents($price) === null)) {
            return [];
        }
        $expectedId = shopVariantId($label, $sku);
        if (!hash_equals($expectedId, $id) || isset($seen[$id])) {
            return [];
        }
        $seen[$id] = true;
        $variants[] = ['id' => $id, 'label' => $label, 'sku' => $sku, 'price' => $price, 'stock' => $stock];
    }
    return $variants;
}

/** 非空规格 JSON 必须是空数组或能完整通过严格解析；损坏配置不得退化成普通商品继续卖。 */
function shopProductVariantsJsonValid(?string $json): bool
{
    if ($json === null || trim($json) === '') {
        return true;
    }
    try {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return false;
    }
    if ($decoded === []) {
        return true;
    }
    return shopProductVariantsFromJson($json) !== [];
}

/**
 * 后台逐行格式：规格名称 | SKU | 售价（空=继承） | 库存。
 * @return array{ok:bool,error:string,value:?string,variants:list<array{id:string,label:string,sku:string,price:?string,stock:int}>,stock:int}
 */
function shopNormalizeVariantConfig(string $raw): array
{
    $fail = static fn(): array => [
        'ok' => false, 'error' => 'shop_err_variants', 'value' => null, 'variants' => [], 'stock' => 0,
    ];
    if (mb_strlen($raw) > 20000) {
        return $fail();
    }
    $variants = [];
    $seenIds = [];
    $seenLabels = [];
    $totalStock = 0;
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) !== 4) {
            return $fail();
        }
        [$label, $sku, $priceRaw, $stockRaw] = $parts;
        if ($label === '' || mb_strlen($label) > 100 || mb_strlen($sku) > 64
            || shopShippingLikeControlChars($label) || shopShippingLikeControlChars($sku)) {
            return $fail();
        }
        $priceCents = $priceRaw === '' ? null : shopValidSalePriceCents($priceRaw);
        $stock = shopValidStock($stockRaw);
        if (($priceRaw !== '' && $priceCents === null) || $stock === null) {
            return $fail();
        }
        $id = shopVariantId($label, $sku);
        $labelKey = mb_strtolower($label);
        if (isset($seenIds[$id]) || isset($seenLabels[$labelKey])) {
            return $fail();
        }
        $seenIds[$id] = true;
        $seenLabels[$labelKey] = true;
        $totalStock += $stock;
        if ($totalStock > 2147483647 || count($variants) >= 50) {
            return $fail();
        }
        $variants[] = [
            'id' => $id,
            'label' => $label,
            'sku' => $sku,
            'price' => $priceCents === null ? null : shopCentsToDecimal($priceCents),
            'stock' => $stock,
        ];
    }
    return [
        'ok' => true,
        'error' => '',
        'value' => $variants === [] ? null : json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'variants' => $variants,
        'stock' => $totalStock,
    ];
}

/** variants.php 不依赖 shipping.php，复用同样的控制字符边界。 */
function shopShippingLikeControlChars(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1;
}

function shopVariantConfigToLines(?string $json): string
{
    $lines = [];
    foreach (shopProductVariantsFromJson($json) as $variant) {
        $lines[] = implode(' | ', [
            $variant['label'], $variant['sku'], $variant['price'] ?? '', (string) $variant['stock'],
        ]);
    }
    return implode("\n", $lines);
}

/** @param list<array{id:string,label:string,sku:string,price:?string,stock:int}> $variants */
function shopVariantFind(array $variants, string $variantId): ?array
{
    foreach ($variants as $variant) {
        if (hash_equals($variant['id'], $variantId)) {
            return $variant;
        }
    }
    return null;
}

/**
 * 调用方须已在同一事务中锁住 shop_products 行。变体不存在或库存不足返回 false。
 * 总库存始终重算为各变体库存之和，避免 JSON 与 stock 漂移。
 */
function shopVariantAdjustLocked(int $canonicalId, string $variantId, int $delta): bool
{
    $row = db()->fetchOne(
        'SELECT specs_json FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?',
        [$canonicalId]
    );
    if ($row === null) {
        return false;
    }
    $variants = shopProductVariantsFromJson(isset($row['specs_json']) ? (string) $row['specs_json'] : null);
    $found = false;
    $total = 0;
    foreach ($variants as &$variant) {
        if (hash_equals($variant['id'], $variantId)) {
            $next = $variant['stock'] + $delta;
            if ($next < 0 || $next > 2147483647) {
                return false;
            }
            $variant['stock'] = $next;
            $found = true;
        }
        $total += $variant['stock'];
    }
    unset($variant);
    if (!$found || $total > 2147483647) {
        return false;
    }
    db()->update('shop_products', [
        'specs_json' => json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'stock' => $total,
        'updated_at' => time(),
    ], 'product_id = ?', [$canonicalId]);
    return true;
}
