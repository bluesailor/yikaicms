<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/plugins/shop/lib/site-template.php';
require_once ROOT_PATH . '/plugins/shop/register.php';

final class ShopSiteTemplateTest extends TestCase
{
    protected function schemaSql(): array
    {
        $sql = [
            'CREATE TABLE products (
                id INTEGER PRIMARY KEY, translation_group_id INTEGER NOT NULL DEFAULT 0,
                status INTEGER NOT NULL DEFAULT 1, deleted_at INTEGER NULL
            )',
            'CREATE TABLE settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\',
                "key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\',
                tip TEXT DEFAULT \'\', options TEXT, sort_order INTEGER DEFAULT 0
            )',
        ];
        foreach (shopTableSchemas() as $schema) {
            $sql[] = str_replace('{p}', DB_PREFIX, $schema['sqlite']);
            foreach ($schema['sqlite_indexes'] ?? [] as $index) {
                $sql[] = str_replace('{p}', DB_PREFIX, $index);
            }
        }
        return $sql;
    }

    protected function setUp(): void
    {
        parent::setUp();
        settingModel()->clearCache();
        $this->insertRow('products', [
            'id' => 41, 'translation_group_id' => 41, 'status' => 1, 'deleted_at' => null,
        ]);
        $this->insertRow('products', [
            'id' => 42, 'translation_group_id' => 41, 'status' => 1, 'deleted_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        // 断言在 commit 之前失败时事务会一直开着，连接是共享的，后面的测试就全报
        // "already an active transaction"——一处失败被放大成一串假失败。
        if (db()->getPdo()->inTransaction()) {
            db()->rollback();
        }
        parent::tearDown();
    }

    public function testRegisteredExportUsesStableExplicitPublicContract(): void
    {
        $variant = [[
            'id' => shopVariantId('红色', 'RED'), 'label' => '红色', 'sku' => 'RED',
            'price' => '12.50', 'stock' => 3,
        ]];
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'MAIN', 'price' => '10.5', 'stock' => 3,
            'status' => 1, 'specs_json' => json_encode($variant, JSON_UNESCAPED_UNICODE),
            'sales' => 987, 'created_at' => 111, 'updated_at' => 222,
        ]);
        $this->insertRow('shop_products', [
            'product_id' => 999, 'sku' => 'DANGLING', 'price' => '9.99', 'stock' => 1,
            'status' => 1, 'specs_json' => null, 'sales' => 1, 'created_at' => 1, 'updated_at' => 1,
        ]);
        settingModel()->saveBatch([
            'show_price' => '1',
            'shop_shipping_excluded_provinces' => '["海南省","北京市"]',
            'shop_shipping_region_surcharges' => "海南省 / 三沙市=2.5",
            'shop_shipping_carriers' => "顺丰速运\n顺丰速运\n京东物流",
        ]);

        $entry = apply_filters('site_template_plugin_export', [], 'shop');

        self::assertSame(1, $entry['contract']);
        self::assertSame('shop/catalog', $entry['schema']['id']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $entry['schema']['sha256']);
        self::assertTrue($entry['state']['replaceable']);
        self::assertCount(1, $entry['payload']['products']);
        self::assertSame([
            'product_id' => 41, 'sku' => 'MAIN', 'price' => '10.50', 'stock' => 3,
            'status' => 1,
            'specs_json' => json_encode($variant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], $entry['payload']['products'][0]);
        self::assertSame('["北京市","海南省"]', $entry['payload']['settings']['shop_shipping_excluded_provinces']);
        self::assertSame('海南省/三沙市 = 2.50', $entry['payload']['settings']['shop_shipping_region_surcharges']);
        self::assertSame("顺丰速运\n京东物流", $entry['payload']['settings']['shop_shipping_carriers']);
        self::assertSame(shopSiteTemplateSchema(), $entry['schema']);
    }

    public function testExportNeverContainsPrivateRowsOrSensitiveSettings(): void
    {
        $secret = 'PRIVATE-MARKER-7cf6';
        settingModel()->saveBatch([
            'shop_manual_payment_methods' => $secret,
            'shop_wechat_mch_id' => $secret,
            'shop_wechat_api_v3_key' => $secret,
            'shop_alipay_private_key' => $secret,
            'shop_alipay_cert_path' => $secret,
        ]);
        $this->insertRow('shop_orders', ['order_no' => $secret, 'contact_json' => $secret, 'address_json' => $secret]);
        $this->insertRow('shop_order_items', ['order_id' => 1, 'product_id' => 41, 'snapshot_json' => $secret]);
        $this->insertRow('shop_payments', ['order_id' => 1, 'gateway_trade_no' => $secret, 'raw_notify' => $secret]);
        $this->insertRow('shop_payment_notifications', [
            'gateway' => 'offline', 'notify_hash' => hash('sha256', $secret), 'process_note' => $secret,
        ]);
        $this->insertRow('shop_refunds', ['order_id' => 1, 'payment_id' => 1, 'reason' => $secret]);
        $this->insertRow('shop_member_addresses', [
            'member_id' => 9, 'contact_json' => $secret, 'address_json' => $secret,
        ]);

        $entry = shopSiteTemplateExport();
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertFalse($entry['state']['replaceable']);
        self::assertStringNotContainsString($secret, $encoded);
        foreach (['orders', 'order_items', 'payments', 'payment_notifications', 'refunds', 'member_addresses',
                     'manual_payment', 'wechat', 'alipay', 'certificate', 'private_key', 'api_key'] as $needle) {
            self::assertStringNotContainsString($needle, strtolower(json_encode($entry['payload'], JSON_THROW_ON_ERROR)));
        }
    }

    public function testImportRejectsUnknownFieldsBadSkuMoneyVariantsAndShipping(): void
    {
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'OK', 'price' => '10.00', 'stock' => 0,
            'status' => 1, 'specs_json' => null,
        ]);
        $base = shopSiteTemplateExport()['payload'];
        $bad = [];
        $case = $base;
        $case['products'][0]['sales'] = 1;
        $bad['unknown field'] = $case;
        $case = $base;
        $case['products'][0]['sku'] = "BAD\nSKU";
        $bad['bad sku'] = $case;
        $case = $base;
        $case['products'][0]['price'] = '0.00';
        $bad['bad money'] = $case;
        $case = $base;
        $case['products'][0]['stock'] = 2147483648;
        $bad['stock overflow'] = $case;
        $case = $base;
        $case['products'][0]['stock'] = 1;
        $case['products'][0]['specs_json'] = json_encode([[
            'id' => shopVariantId('A', 'A'), 'label' => 'A', 'sku' => 'A',
            'price' => '1.00', 'stock' => 1, 'cost' => 'secret',
        ]], JSON_THROW_ON_ERROR);
        $bad['bad variant field'] = $case;
        $case = $base;
        $case['settings']['shop_shipping_fee_cents'] = '-1';
        $bad['bad shipping amount'] = $case;
        $case = $base;
        $case['settings']['shop_shipping_region_surcharges'] = '海南省/三沙市 = 0';
        $bad['bad shipping rule'] = $case;

        foreach ($bad as $label => $payload) {
            db()->beginTransaction();
            $caught = null;
            try {
                shopSiteTemplateImport($payload);
            } catch (RuntimeException $e) {
                $caught = $e;
            } finally {
                db()->rollback();
            }
            self::assertNotNull($caught, 'Expected rejection: ' . $label);
            self::assertSame('st_invalid', $caught->getMessage(), $label);
            self::assertSame('OK', db()->fetchColumn('SELECT sku FROM shop_products WHERE product_id = 41'));
        }
    }

    public function testPrivateActivityRejectsImportBeforePublicDeletion(): void
    {
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'KEEP', 'price' => '10.00', 'stock' => 0,
            'status' => 1, 'specs_json' => null,
        ]);
        $payload = shopSiteTemplateExport()['payload'];
        $this->insertRow('shop_orders', ['order_no' => 'ORDER-1']);

        db()->beginTransaction();
        $caught = null;
        try {
            shopSiteTemplateImport($payload);
        } catch (RuntimeException $e) {
            $caught = $e;
        } finally {
            db()->rollback();
        }
        self::assertNotNull($caught, 'Expected private-state rejection');
        self::assertSame('st_not_fresh', $caught->getMessage());
        self::assertSame('KEEP', db()->fetchColumn('SELECT sku FROM shop_products WHERE product_id = 41'));
    }

    public function testImportAcceptsGenericCoreCanonicalKeyOrder(): void
    {
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'CANONICAL', 'price' => '10.00', 'stock' => 0,
            'status' => 1, 'specs_json' => null,
        ]);
        $payload = shopSiteTemplateExport()['payload'];
        foreach ($payload['products'] as &$row) {
            ksort($row);
        }
        unset($row);
        ksort($payload['settings']);
        ksort($payload);

        db()->beginTransaction();
        shopSiteTemplateImport($payload);
        db()->commit();

        self::assertSame('CANONICAL', db()->fetchColumn('SELECT sku FROM shop_products WHERE product_id = 41'));
    }

    public function testHookRoundTripIsExactAndKeepsTargetOnlySecrets(): void
    {
        $variant = [[
            'id' => shopVariantId('大号', 'XL'), 'label' => '大号', 'sku' => 'XL',
            'price' => null, 'stock' => 2,
        ]];
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'SOURCE', 'price' => '88.00', 'stock' => 2,
            'status' => 1, 'specs_json' => json_encode($variant, JSON_UNESCAPED_UNICODE),
            'sales' => 998, 'created_at' => 10, 'updated_at' => 20,
        ]);
        settingModel()->saveBatch(['show_price' => '1', 'shop_shipping_fee_cents' => '999']);
        $source = apply_filters('site_template_plugin_export', [], 'shop')['payload'];

        db()->execute('DELETE FROM shop_products');
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'TARGET', 'price' => '1.00', 'stock' => 0,
            'status' => 0, 'specs_json' => null, 'sales' => 123,
        ]);
        settingModel()->saveBatch([
            'show_price' => '0', 'shop_shipping_fee_cents' => '1',
            'shop_wechat_api_v3_key' => 'target-secret',
            'shop_manual_payment_methods' => 'bank-secret',
        ]);

        db()->beginTransaction();
        do_action('site_template_plugin_import', $source, 'shop');
        $after = apply_filters('site_template_plugin_export', [], 'shop');
        self::assertSame($source, $after['payload']);
        db()->commit();

        $stored = db()->fetchOne('SELECT * FROM shop_products WHERE product_id = 41');
        self::assertSame('SOURCE', $stored['sku']);
        self::assertSame(0, (int) $stored['sales']);
        self::assertGreaterThan(0, (int) $stored['created_at']);
        self::assertSame('target-secret', settingModel()->get('shop_wechat_api_v3_key'));
        self::assertSame('bank-secret', settingModel()->get('shop_manual_payment_methods'));
    }

    public function testDatabaseFailureRollsBackDeletionAndSettings(): void
    {
        $this->insertRow('shop_products', [
            'product_id' => 41, 'sku' => 'SOURCE', 'price' => '10.00', 'stock' => 0,
            'status' => 1, 'specs_json' => null,
        ]);
        settingModel()->saveBatch(['show_price' => '1']);
        $source = shopSiteTemplateExport()['payload'];
        db()->execute('UPDATE shop_products SET sku = ?', ['KEEP']);
        settingModel()->saveBatch(['show_price' => '0']);
        db()->execute("CREATE TRIGGER reject_shop_template BEFORE INSERT ON shop_products BEGIN SELECT RAISE(ABORT, 'reject'); END");

        db()->beginTransaction();
        $caught = null;
        try {
            shopSiteTemplateImport($source);
        } catch (\Throwable $e) {
            $caught = $e;
        } finally {
            if (db()->getPdo()->inTransaction()) {
                db()->rollback();
            }
        }
        self::assertNotNull($caught, 'Expected injected database failure');
        self::assertStringContainsString('reject', $caught->getMessage());

        self::assertSame('KEEP', db()->fetchColumn('SELECT sku FROM shop_products WHERE product_id = 41'));
        settingModel()->clearCache();
        self::assertSame('0', settingModel()->get('show_price'));
    }

    public function testMissingPublicColumnFailsSchemaCheck(): void
    {
        db()->execute('ALTER TABLE shop_products RENAME TO shop_products_old');
        db()->execute('CREATE TABLE shop_products (
            product_id INTEGER PRIMARY KEY, sku TEXT NOT NULL, price TEXT NULL,
            stock INTEGER NOT NULL, status INTEGER NOT NULL, sales INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0
        )');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('st_schema');
        shopSiteTemplateExport();
    }
}
