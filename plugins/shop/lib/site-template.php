<?php
/** 商城站点模板适配器：只搬运公开商品销售配置，绝不搬运交易或支付数据。 */

declare(strict_types=1);

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/money.php';
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/shipping.php';

/** @return list<string> */
function shopSiteTemplateProductColumns(): array
{
    return ['product_id', 'sku', 'price', 'stock', 'status', 'specs_json'];
}

/** @return list<string> */
function shopSiteTemplatePrivateTables(): array
{
    return [
        'shop_orders', 'shop_order_items', 'shop_payments',
        'shop_payment_notifications', 'shop_refunds', 'shop_member_addresses',
    ];
}

/** @return list<string> */
function shopSiteTemplateAllTables(): array
{
    return array_merge(['shop_products'], shopSiteTemplatePrivateTables());
}

/** @return array<string,string> */
function shopSiteTemplateSettingDefaults(): array
{
    return [
        'show_price' => '0',
        'shop_shipping_fee_cents' => '1500',
        'shop_free_shipping_threshold_cents' => '0',
        'shop_order_expire_minutes' => '30',
        'shop_shipping_excluded_provinces' => '[]',
        'shop_shipping_excluded_regions' => '',
        'shop_shipping_region_surcharges' => '',
        'shop_shipping_carriers' => implode("\n", shopDefaultShippingCarriers()),
    ];
}

/** @return array{id:string,version:int,sha256:string} */
function shopSiteTemplateSchema(): array
{
    // 商城表按需创建（首次打开商城页面才建）：刚启用、还没打开过商城的站点，
    // 导入整站模板时这里要先把表建好，否则会被误判为结构不一致
    shopEnsureSchema();
    if (!db()->tableExists('shop_products')) {
        throw new RuntimeException('st_schema');
    }
    $table = DB_PREFIX . 'shop_products';
    $rows = db()->isSqlite()
        ? db()->fetchAll('PRAGMA table_info(`' . $table . '`)')
        : db()->fetchAll('SHOW COLUMNS FROM `' . $table . '`');
    $actual = array_map(
        static fn(array $row): string => (string) ($row['name'] ?? $row['Field'] ?? ''),
        $rows
    );
    foreach (shopSiteTemplateProductColumns() as $column) {
        if (!in_array($column, $actual, true)) {
            throw new RuntimeException('st_schema');
        }
    }

    // 指纹只由显式、稳定的公开合同生成；私表和实现列绝不能意外进入合同。
    $contract = [
        'payload' => [
            'products' => [
                'fields' => [
                    'product_id' => 'positive-int:canonical-core-product',
                    'sku' => 'string:trimmed:no-control:max-64',
                    'price' => 'null-or-positive-decimal-10-2',
                    'stock' => 'int:0-2147483647',
                    'status' => 'int:0-or-1',
                    'specs_json' => 'null-or-canonical-json-list',
                ],
                'max_rows' => 10000,
                'specs' => [
                    'id' => 'sha256-derived-string-20',
                    'label' => 'string:trimmed:no-control:max-100',
                    'sku' => 'string:trimmed:no-control:max-64',
                    'price' => 'null-or-positive-decimal-10-2',
                    'stock' => 'int:0-2147483647',
                    'max_rows' => 50,
                    'sum_stock_equals_product_stock' => true,
                ],
            ],
            'settings' => [
                'show_price' => 'string:0-or-1',
                'shop_shipping_fee_cents' => 'canonical-nonnegative-int-string:max-9999999999',
                'shop_free_shipping_threshold_cents' => 'canonical-nonnegative-int-string:max-9999999999',
                'shop_order_expire_minutes' => 'canonical-int-string:1-10080',
                'shop_shipping_excluded_provinces' => 'canonical-json-list:mainland-province',
                'shop_shipping_excluded_regions' => 'canonical-region-rules:max-100',
                'shop_shipping_region_surcharges' => 'canonical-positive-money-rules:max-100',
                'shop_shipping_carriers' => 'canonical-lines:max-30',
            ],
        ],
        'private_tables' => shopSiteTemplatePrivateTables(),
    ];
    $json = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return ['id' => 'shop/catalog', 'version' => 1, 'sha256' => 'sha256:' . hash('sha256', $json)];
}

/** @return array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}} */
function shopSiteTemplateExport(): array
{
    $schema = shopSiteTemplateSchema();
    $payload = shopSiteTemplatePayload();
    $privateActivity = shopSiteTemplatePrivateActivity();
    $replaceable = true;
    foreach ($privateActivity as $active) {
        if ($active) {
            $replaceable = false;
            break;
        }
    }
    $stateJson = json_encode(
        ['payload' => $payload, 'private_activity' => $privateActivity],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    return [
        'contract' => 1,
        'schema' => $schema,
        'payload' => $payload,
        'state' => ['sha256' => 'sha256:' . hash('sha256', $stateJson), 'replaceable' => $replaceable],
    ];
}

/** @return array{products:list<array<string,mixed>>,settings:array<string,string>} */
function shopSiteTemplatePayload(): array
{
    $canonicalIds = shopSiteTemplateCanonicalProductIds();
    $rows = db()->fetchAll(
        'SELECT product_id, sku, price, stock, status, specs_json FROM '
        . DB_PREFIX . 'shop_products ORDER BY product_id'
    );
    $products = [];
    foreach ($rows as $row) {
        $id = (int) ($row['product_id'] ?? 0);
        if (isset($canonicalIds[$id])) {
            $products[] = shopSiteTemplateNormalizeProduct($row);
        }
    }

    $settings = [];
    $model = settingModel();
    $model->clearCache();
    foreach (shopSiteTemplateSettingDefaults() as $key => $default) {
        $settings[$key] = (string) $model->get($key, $default);
    }
    $settings = shopSiteTemplateNormalizeSettings($settings);
    return ['products' => $products, 'settings' => $settings];
}

/** @return array<int,true> */
function shopSiteTemplateCanonicalProductIds(): array
{
    if (!db()->tableExists('products')) {
        throw new RuntimeException('st_schema');
    }
    $rows = db()->fetchAll(
        'SELECT id, translation_group_id, status, deleted_at FROM ' . DB_PREFIX . 'products ORDER BY id'
    );
    $ids = [];
    foreach ($rows as $row) {
        if ((int) ($row['status'] ?? 1) !== 1) {
            continue;
        }
        if (($row['deleted_at'] ?? null) !== null && (string) $row['deleted_at'] !== ''
            && (int) $row['deleted_at'] > 0) {
            continue;
        }
        $id = (int) (($row['translation_group_id'] ?? 0) ?: ($row['id'] ?? 0));
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return $ids;
}

/** @param array<string,mixed> $row @return array{product_id:int,sku:string,price:?string,stock:int,status:int,specs_json:?string} */
function shopSiteTemplateNormalizeProduct(array $row): array
{
    if (count($row) !== 6 || array_diff(array_keys($row), shopSiteTemplateProductColumns()) !== []) {
        throw new RuntimeException('st_invalid');
    }
    $id = $row['product_id'] ?? null;
    $sku = $row['sku'] ?? null;
    $price = $row['price'] ?? null;
    $stock = $row['stock'] ?? null;
    $status = $row['status'] ?? null;
    $specs = $row['specs_json'] ?? null;
    if ((!is_int($id) && !(is_string($id) && preg_match('/^[1-9]\d*$/D', $id) === 1)) || (int) $id <= 0
        || !is_string($sku) || trim($sku) !== $sku || mb_strlen($sku) > 64
        || shopShippingLikeControlChars($sku)
        || (!is_int($stock) && !(is_string($stock) && preg_match('/^\d+$/D', $stock) === 1))
        || shopValidStock((string) $stock) === null || (int) $stock > 2147483647
        || (!is_int($status) && !(is_string($status) && preg_match('/^[01]$/D', $status) === 1))
        || !in_array((int) $status, [0, 1], true)
        || ($price !== null && !is_string($price))
        || ($specs !== null && !is_string($specs))) {
        throw new RuntimeException('st_invalid');
    }
    $normalizedPrice = null;
    if ($price !== null && $price !== '') {
        $cents = shopValidSalePriceCents($price);
        if ($cents === null) {
            throw new RuntimeException('st_invalid');
        }
        $normalizedPrice = shopCentsToDecimal($cents);
    }
    $normalizedSpecs = shopSiteTemplateNormalizeSpecs($specs, (int) $stock);
    return [
        'product_id' => (int) $id,
        'sku' => $sku,
        'price' => $normalizedPrice,
        'stock' => (int) $stock,
        'status' => (int) $status,
        'specs_json' => $normalizedSpecs,
    ];
}

function shopSiteTemplateNormalizeSpecs(?string $json, int $productStock): ?string
{
    if ($json === null || trim($json) === '' || trim($json) === '[]') {
        return null;
    }
    try {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('st_invalid', 0, $e);
    }
    if (!is_array($decoded) || $decoded === [] || !shopSiteTemplateIsList($decoded) || count($decoded) > 50) {
        throw new RuntimeException('st_invalid');
    }
    $expectedKeys = ['id', 'label', 'sku', 'price', 'stock'];
    foreach ($decoded as $variant) {
        if (!is_array($variant) || count($variant) !== 5
            || array_diff(array_keys($variant), $expectedKeys) !== []
            || !is_string($variant['id'] ?? null) || !is_string($variant['label'] ?? null)
            || !is_string($variant['sku'] ?? null)
            || (!is_string($variant['price'] ?? null) && ($variant['price'] ?? null) !== null)
            || !is_int($variant['stock'] ?? null)) {
            throw new RuntimeException('st_invalid');
        }
    }
    $variants = shopProductVariantsFromJson($json);
    if (count($variants) !== count($decoded)) {
        throw new RuntimeException('st_invalid');
    }
    $stock = 0;
    foreach ($variants as &$variant) {
        if (shopShippingLikeControlChars($variant['label']) || shopShippingLikeControlChars($variant['sku'])) {
            throw new RuntimeException('st_invalid');
        }
        if ($variant['price'] !== null) {
            $cents = shopValidSalePriceCents($variant['price']);
            if ($cents === null) {
                throw new RuntimeException('st_invalid');
            }
            $variant['price'] = shopCentsToDecimal($cents);
        }
        $stock += $variant['stock'];
        if ($stock > 2147483647) {
            throw new RuntimeException('st_invalid');
        }
    }
    unset($variant);
    if ($stock !== $productStock) {
        throw new RuntimeException('st_invalid');
    }
    return json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @param array<mixed> $value */
function shopSiteTemplateIsList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/** @param array<string,mixed> $expected @param array<string,mixed> $actual */
function shopSiteTemplateExactMap(array $expected, array $actual): bool
{
    if (count($expected) !== count($actual)) {
        return false;
    }
    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
            return false;
        }
    }
    return true;
}

/** @param array<string,mixed> $settings @return array<string,string> */
function shopSiteTemplateNormalizeSettings(array $settings): array
{
    $defaults = shopSiteTemplateSettingDefaults();
    if (count($settings) !== count($defaults) || array_diff(array_keys($settings), array_keys($defaults)) !== []) {
        throw new RuntimeException('st_invalid');
    }
    foreach ($settings as $value) {
        if (!is_string($value)) {
            throw new RuntimeException('st_invalid');
        }
    }
    if (!in_array($settings['show_price'], ['0', '1'], true)) {
        throw new RuntimeException('st_invalid');
    }
    foreach (['shop_shipping_fee_cents', 'shop_free_shipping_threshold_cents'] as $key) {
        $value = $settings[$key];
        if (preg_match('/^(?:0|[1-9]\d*)$/D', $value) !== 1 || strlen($value) > 10
            || (int) $value > shopMoneyMaxCents()) {
            throw new RuntimeException('st_invalid');
        }
        $settings[$key] = (string) (int) $value;
    }
    $expire = $settings['shop_order_expire_minutes'];
    if (preg_match('/^[1-9]\d*$/D', $expire) !== 1 || strlen($expire) > 5
        || (int) $expire > 10080) {
        throw new RuntimeException('st_invalid');
    }
    $settings['shop_order_expire_minutes'] = (string) (int) $expire;

    try {
        $provinces = json_decode($settings['shop_shipping_excluded_provinces'], true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('st_invalid', 0, $e);
    }
    if (!is_array($provinces) || !shopSiteTemplateIsList($provinces)) {
        throw new RuntimeException('st_invalid');
    }
    $selected = [];
    foreach ($provinces as $province) {
        if (!is_string($province) || !in_array($province, shopMainlandProvinces(), true) || isset($selected[$province])) {
            throw new RuntimeException('st_invalid');
        }
        $selected[$province] = true;
    }
    $ordered = array_values(array_filter(
        shopMainlandProvinces(),
        static fn(string $province): bool => isset($selected[$province])
    ));
    $settings['shop_shipping_excluded_provinces'] = json_encode(
        $ordered,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $regions = shopNormalizeExcludedRegionConfig($settings['shop_shipping_excluded_regions']);
    $surcharges = shopNormalizeRegionSurchargeConfig($settings['shop_shipping_region_surcharges']);
    $carriers = shopNormalizeCarrierConfig($settings['shop_shipping_carriers']);
    if (!$regions['ok'] || !$surcharges['ok'] || !$carriers['ok']) {
        throw new RuntimeException('st_invalid');
    }
    $settings['shop_shipping_excluded_regions'] = $regions['value'];
    $settings['shop_shipping_region_surcharges'] = $surcharges['value'];
    $settings['shop_shipping_carriers'] = $carriers['value'];
    return $settings;
}

/** @return array<string,bool> */
function shopSiteTemplatePrivateActivity(): array
{
    $activity = [];
    foreach (shopSiteTemplatePrivateTables() as $table) {
        if (!db()->tableExists($table)) {
            throw new RuntimeException('st_schema');
        }
        // 私表可能很大，只探测一条主键；绝不 fetchAll、序列化或哈希交易正文。
        $activity[$table] = db()->fetchColumn(
            'SELECT id FROM ' . DB_PREFIX . $table . ' ORDER BY id LIMIT 1'
        ) !== false;
    }
    return $activity;
}

/** @param array<string,mixed> $payload */
function shopSiteTemplateImport(array $payload): void
{
    shopSiteTemplateSchema();
    if (array_keys($payload) !== ['products', 'settings']
        || !is_array($payload['products']) || !shopSiteTemplateIsList($payload['products'])
        || count($payload['products']) > 10000 || !is_array($payload['settings'])) {
        throw new RuntimeException('st_invalid');
    }
    $products = [];
    $seen = [];
    foreach ($payload['products'] as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('st_invalid');
        }
        $normalized = shopSiteTemplateNormalizeProduct($row);
        if (!shopSiteTemplateExactMap($normalized, $row) || isset($seen[$normalized['product_id']])) {
            throw new RuntimeException('st_invalid');
        }
        $seen[$normalized['product_id']] = true;
        $products[] = $normalized;
    }
    $settings = shopSiteTemplateNormalizeSettings($payload['settings']);
    if (!shopSiteTemplateExactMap($settings, $payload['settings'])) {
        throw new RuntimeException('st_invalid');
    }
    if (!db()->getPdo()->inTransaction()) {
        throw new RuntimeException('st_plugin_import');
    }
    shopSiteTemplateAssertTablesImportable();
    shopSiteTemplateLockAndAssertPrivateEmpty();
    shopSiteTemplateLockPublicDependencies();
    $canonicalIds = shopSiteTemplateCanonicalProductIds();
    foreach (array_keys($seen) as $id) {
        if (!isset($canonicalIds[$id])) {
            throw new RuntimeException('st_invalid');
        }
    }

    $productTable = DB_PREFIX . 'shop_products';
    db()->fetchAll('SELECT product_id FROM ' . $productTable . ' ORDER BY product_id'
        . (db()->isSqlite() ? '' : ' FOR UPDATE'));
    db()->execute('DELETE FROM ' . $productTable);
    $now = time();
    foreach ($products as $row) {
        db()->insert('shop_products', $row + ['sales' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }
    settingModel()->saveBatch($settings);
    settingModel()->clearCache();
}

function shopSiteTemplateAssertTablesImportable(): void
{
    $tables = array_merge(shopSiteTemplateAllTables(), ['products', 'settings']);
    foreach ($tables as $table) {
        if (!db()->tableExists($table)) {
            throw new RuntimeException('st_schema');
        }
    }
    if (db()->isSqlite()) {
        return;
    }
    foreach ($tables as $table) {
        $row = db()->fetchOne(
            'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [DB_NAME, DB_PREFIX . $table]
        );
        if ($row === null || strcasecmp((string) ($row['ENGINE'] ?? ''), 'InnoDB') !== 0) {
            throw new RuntimeException('st_schema');
        }
    }
}

function shopSiteTemplateLockPublicDependencies(): void
{
    if (db()->isSqlite()) {
        db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'products ORDER BY id');
    } else {
        db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'products ORDER BY id FOR UPDATE');
    }
    $keys = array_keys(shopSiteTemplateSettingDefaults());
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    db()->fetchAll(
        'SELECT `key` FROM ' . DB_PREFIX . 'settings WHERE `key` IN (' . $placeholders . ') ORDER BY `key`'
        . (db()->isSqlite() ? '' : ' FOR UPDATE'),
        $keys
    );
}

function shopSiteTemplateLockAndAssertPrivateEmpty(): void
{
    foreach (shopSiteTemplatePrivateTables() as $table) {
        $row = db()->fetchOne(
            'SELECT id FROM ' . DB_PREFIX . $table . ' ORDER BY id LIMIT 1'
            . (db()->isSqlite() ? '' : ' FOR UPDATE')
        );
        if ($row !== null) {
            throw new RuntimeException('st_not_fresh');
        }
    }
}
