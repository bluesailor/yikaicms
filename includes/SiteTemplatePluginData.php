<?php
declare(strict_types=1);

/** Fixed public-data adapters; packages never choose SQL, table names or executable handlers. */
final class SiteTemplatePluginData
{
    public const TABLES = ['shop_products', 'shop_orders', 'shop_order_items', 'shop_payments', 'shop_payment_notifications', 'shop_refunds', 'shop_member_addresses'];
    private const SUPPORTED = ['stay-inquiry', 'pet-cart-demo', 'shop'];
    private const SALES_FIELDS = ['product_id', 'sku', 'price', 'stock', 'status', 'specs_json'];

    /** @param list<array{slug:string,version:string}> $plugins */
    public static function snapshot(array $plugins, array $data): array
    {
        $payload = [];
        foreach ($plugins as $plugin) {
            $slug = $plugin['slug'];
            if (!in_array($slug, self::SUPPORTED, true)) continue;
            $payload[$slug] = ['version' => 1];
            if ($slug !== 'shop') continue;
            self::assertShopSchema();
            $ids = self::productIds($data);
            $rows = db()->fetchAll('SELECT product_id, sku, price, stock, status, specs_json FROM ' . DB_PREFIX . 'shop_products ORDER BY product_id');
            $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($ids[(int) $row['product_id']])));
            foreach ($rows as &$row) {
                foreach (['product_id', 'stock', 'status'] as $key) $row[$key] = (int) $row[$key];
                $row['sku'] = (string) $row['sku'];
                $row['price'] = $row['price'] === null ? null : (string) $row['price'];
            }
            unset($row);
            $payload[$slug] += ['products' => $rows, 'settings' => ['show_price' => (string) config('show_price', '0')]];
        }
        ksort($payload);
        self::validate($payload, $plugins, $data);
        return $payload;
    }

    /** Data contracts are versioned independently from plugin releases. */
    public static function validate(array $payload, array $plugins, array $data): void
    {
        $required = array_fill_keys(array_column($plugins, 'slug'), true);
        foreach (self::SUPPORTED as $slug) {
            if (isset($required[$slug]) && !isset($payload[$slug])) throw new RuntimeException('st_invalid');
        }
        foreach ($payload as $slug => $entry) {
            if (!is_string($slug) || !in_array($slug, self::SUPPORTED, true) || !isset($required[$slug])
                || !is_array($entry) || ($entry['version'] ?? null) !== 1) throw new RuntimeException('st_invalid');
            if ($slug !== 'shop') {
                if (array_keys($entry) !== ['version']) throw new RuntimeException('st_invalid');
                if ($slug === 'stay-inquiry') {
                    $forms = array_column($data['tables']['form_templates'] ?? [], null, 'slug');
                    if (!isset($forms['room-booking'])) throw new RuntimeException('st_references');
                }
                if ($slug === 'pet-cart-demo' && !isset($required['shop'])) throw new RuntimeException('st_references');
                continue;
            }
            if (array_keys($entry) !== ['version', 'products', 'settings'] || !is_array($entry['products'])
                || count($entry['products']) > 10000 || !is_array($entry['settings'])
                || array_keys($entry['settings']) !== ['show_price']
                || !in_array($entry['settings']['show_price'], ['0', '1'], true)) throw new RuntimeException('st_invalid');
            $ids = self::productIds($data);
            $seen = [];
            foreach ($entry['products'] as $row) {
                if (!is_array($row) || array_keys($row) !== self::SALES_FIELDS) throw new RuntimeException('st_invalid');
                $id = $row['product_id'];
                if (!is_int($id) || $id <= 0 || !isset($ids[$id]) || isset($seen[$id])) throw new RuntimeException('st_references');
                $seen[$id] = true;
                if (!is_string($row['sku']) || mb_strlen($row['sku']) > 64 || preg_match('/[\x00-\x1f\x7f]/u', $row['sku'])
                    || !self::priceValid($row['price']) || !is_int($row['stock']) || $row['stock'] < 0 || $row['stock'] > 2147483647
                    || !in_array($row['status'], [0, 1], true)) throw new RuntimeException('st_invalid');
                self::validateVariants($row['specs_json'], $row['stock']);
            }
        }
    }

    private static function priceValid(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value) === 1);
    }

    private static function validateVariants(mixed $json, int $stock): void
    {
        if ($json === null || $json === '') return;
        if (!is_string($json) || strlen($json) > 100000) throw new RuntimeException('st_invalid');
        $variants = json_decode($json, true, 16);
        if (!is_array($variants) || count($variants) > 50 || ($variants !== [] && array_keys($variants) !== range(0, count($variants) - 1))) throw new RuntimeException('st_invalid');
        $seen = [];
        $total = 0;
        foreach ($variants as $row) {
            if (!is_array($row) || array_keys($row) !== ['id', 'label', 'sku', 'price', 'stock']
                || !is_string($row['id']) || !is_string($row['label']) || trim($row['label']) === '' || mb_strlen($row['label']) > 100
                || !is_string($row['sku']) || mb_strlen($row['sku']) > 64
                || preg_match('/[\x00-\x1f\x7f]/u', $row['label'] . $row['sku'])
                || !self::priceValid($row['price']) || !is_int($row['stock']) || $row['stock'] < 0 || $row['stock'] > 2147483647) throw new RuntimeException('st_invalid');
            $expected = substr(hash('sha256', mb_strtolower(trim($row['label'])) . "\0" . trim($row['sku'])), 0, 20);
            if (!hash_equals($expected, $row['id']) || isset($seen[$row['id']])) throw new RuntimeException('st_invalid');
            $seen[$row['id']] = true;
            $total += $row['stock'];
        }
        if ($variants !== [] && $total !== $stock) throw new RuntimeException('st_invalid');
    }

    private static function productIds(array $data): array
    {
        $ids = [];
        foreach ($data['tables']['products'] ?? [] as $product) {
            $id = (int) ($product['translation_group_id'] ?? 0) ?: (int) ($product['id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
        return $ids;
    }

    private static function assertShopSchema(): void
    {
        if (!db()->tableExists('shop_products')) throw new RuntimeException('st_schema');
        $rows = db()->isSqlite() ? db()->fetchAll('PRAGMA table_info(`' . DB_PREFIX . 'shop_products`)')
            : db()->fetchAll('SHOW COLUMNS FROM `' . DB_PREFIX . 'shop_products`');
        $columns = array_map(static fn(array $row): string => (string) ($row['name'] ?? $row['Field']), $rows);
        if (array_diff(array_merge(self::SALES_FIELDS, ['sales', 'created_at', 'updated_at']), $columns)) throw new RuntimeException('st_schema');
    }

    /** Explicitly refuse to combine imported selling configuration with existing customer activity. */
    public static function assertTarget(array $payload): void
    {
        if (!isset($payload['shop'])) return;
        self::assertShopSchema();
        foreach (array_slice(self::TABLES, 1) as $table) {
            if (db()->tableExists($table) && (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . $table) > 0) throw new RuntimeException('st_not_fresh');
        }
    }

    /** Local journal only: never include this unrestricted backup in a package. */
    public static function backup(array $payload): array
    {
        if (!isset($payload['shop'])) return [];
        $settings = settingModel()->getAll();
        return ['shop_products' => db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'shop_products ORDER BY product_id'),
            'settings' => array_intersect_key($settings, ['show_price' => true])];
    }

    /** Caller owns the same transaction as the core content replacement. */
    public static function apply(array $payload): void
    {
        if (!isset($payload['shop'])) return;
        self::assertTarget($payload);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'shop_products');
        foreach ($payload['shop']['products'] as $row) db()->insert('shop_products', $row + ['sales' => 0, 'created_at' => time(), 'updated_at' => time()]);
        settingModel()->saveBatch($payload['shop']['settings']);
        settingModel()->clearCache();
    }

    public static function restore(array $backup): void
    {
        if ($backup === []) return;
        self::assertShopSchema();
        db()->execute('DELETE FROM ' . DB_PREFIX . 'shop_products');
        foreach ($backup['shop_products'] as $row) db()->insert('shop_products', $row);
        if (array_key_exists('show_price', $backup['settings'])) settingModel()->saveBatch($backup['settings']);
        else db()->delete('settings', '`key` = ?', ['show_price']);
        settingModel()->clearCache();
    }

    /** Hash private activity locally, never return its rows to export or the browser. */
    public static function fingerprint(): array
    {
        $state = [];
        foreach (self::TABLES as $table) {
            $rows = db()->tableExists($table) ? db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' ORDER BY ' . ($table === 'shop_products' ? 'product_id' : 'id')) : [];
            $state[$table] = hash('sha256', serialize($rows));
        }
        $state['show_price'] = (string) settingModel()->get('show_price', '0');
        return $state;
    }
}
