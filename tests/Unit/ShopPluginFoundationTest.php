<?php
/**
 * G1 权限键注册机制测试（商城立项 §六）+ 商城表结构形状测试（M0-1）。
 *
 * pluginPermissionManifest 是纯函数（目录 + 活跃清单可注入），不触库；
 * 真实状态的包装（pluginPermissions）由 admin 冒烟/e2e 覆盖。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopPluginFoundationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/permissions.php';
        require_once ROOT_PATH . '/plugins/shop/lib/tables.php';
        require_once ROOT_PATH . '/plugins/shop/lib/access.php';
    }

    /** 用临时目录构造声明场景，不依赖站点当前启用了哪些插件。 */
    private static function buildFixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/yk-perm-test-' . uniqid();
        mkdir($dir . '/alpha', 0777, true);
        mkdir($dir . '/beta', 0777, true);
        mkdir($dir . '/bad-slug..x', 0777, true);
        file_put_contents($dir . '/alpha/plugin.json', (string) json_encode([
            'permissions' => [
                'alpha_manage' => ['label' => '甲', 'label_en' => 'Alpha', 'description' => '管理甲'],
                'INVALID KEY' => ['label' => '应被忽略'],   // 键名不合法
                '9starts-digit' => ['label' => '应被忽略'],
            ],
            'admin_permission' => 'alpha_manage',
            'role_presets' => [
                ['key' => 'alpha_manager', 'label' => '甲管理员', 'permissions' => ['edit_product', 'alpha_manage', '*', 'unknown']],
                ['key' => 'broken', 'label' => '坏预设', 'permissions' => ['unknown']],
            ],
        ]));
        file_put_contents($dir . '/beta/plugin.json', (string) json_encode([
            'name' => 'no permissions declared',
        ]));
        file_put_contents($dir . '/bad-slug..x/plugin.json', (string) json_encode([
            'permissions' => ['sneaky_key' => ['label' => 'x']],
        ]));

        return $dir;
    }

    public function testManifestCollectsOnlyValidKeysFromActivePlugins(): void
    {
        $dir = self::buildFixtureDir();
        try {
            // 只有 alpha 启用：beta 未声明、bad-slug 目录名不合法
            $manifest = pluginPermissionManifest($dir, ['alpha']);
            $this->assertSame(['alpha_manage'], array_keys($manifest));
            $this->assertSame('甲', $manifest['alpha_manage']['label']);

            // 未列入活跃清单的插件声明不参与（停用即从角色界面消失的基础）
            $this->assertSame([], pluginPermissionManifest($dir, ['beta']));
            $this->assertSame([], pluginPermissionManifest($dir, ['bad-slug..x']));
        } finally {
            foreach (glob($dir . '/*/plugin.json') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir . '/alpha');
            @rmdir($dir . '/beta');
            @rmdir($dir . '/bad-slug..x');
            @rmdir($dir);
        }
    }

    public function testManifestIgnoresBrokenJson(): void
    {
        $dir = sys_get_temp_dir() . '/yk-perm-test-' . uniqid();
        mkdir($dir . '/gamma', 0777, true);
        file_put_contents($dir . '/gamma/plugin.json', '{broken');
        try {
            $this->assertSame([], pluginPermissionManifest($dir, ['gamma']));
        } finally {
            @unlink($dir . '/gamma/plugin.json');
            @rmdir($dir . '/gamma');
            @rmdir($dir);
        }
    }

    /** 真实仓库里的 shop 插件声明必须能被收集（防 plugin.json 手滑写坏）。 */
    public function testShopPluginDeclaresPermissionsCorrectly(): void
    {
        $manifest = pluginPermissionManifest(ROOT_PATH . '/plugins', ['shop']);
        $this->assertArrayHasKey('shop_manage', $manifest, 'shop 插件应声明 shop_manage');
        $this->assertArrayHasKey('shop_orders', $manifest);
        $meta = json_decode((string) file_get_contents(ROOT_PATH . '/plugins/shop/plugin.json'), true);
        // G1 扩展（M1-d）：admin_permission_any 列出宿主页可进入的权限键，
        // 且每个键都必须已声明（否则宿主页退回超管，只收紧不放宽）
        $any = $meta['admin_permission_any'] ?? [];
        $this->assertIsArray($any);
        $this->assertNotEmpty($any);
        foreach ($any as $key) {
            $this->assertArrayHasKey($key, $manifest, "宿主页键 {$key} 必须在 permissions 里声明");
        }
        $this->assertNotSame('', (string) ($manifest['shop_manage']['description'] ?? ''));
        $this->assertNotSame('', (string) ($manifest['shop_orders']['description'] ?? ''));

        $presets = pluginRolePresetManifest(
            ROOT_PATH . '/plugins',
            ['shop'],
            array_merge(['*'], contentPermTypes(), ['edit_product', 'delete_product', 'shop_manage', 'shop_orders'])
        );
        $this->assertArrayHasKey('shop:shop_manager', $presets);
        $this->assertArrayHasKey('shop:shop_order_operator', $presets);
        $this->assertSame(['shop_orders'], $presets['shop:shop_order_operator']['permissions']);
    }

    public function testPluginRolePresetsKeepOnlyKnownNonSuperPermissions(): void
    {
        $dir = self::buildFixtureDir();
        try {
            $presets = pluginRolePresetManifest($dir, ['alpha'], ['*', 'edit_product', 'alpha_manage']);
            $this->assertSame(['alpha:alpha_manager'], array_keys($presets));
            $this->assertSame(['edit_product', 'alpha_manage'], $presets['alpha:alpha_manager']['permissions']);
        } finally {
            foreach (glob($dir . '/*/plugin.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir . '/alpha');
            @rmdir($dir . '/beta');
            @rmdir($dir . '/bad-slug..x');
            @rmdir($dir);
        }
    }

    public function testShopAdminCapabilitiesStaySeparated(): void
    {
        $this->assertSame('sales', shopAdminResolveView('sales', true, false));
        $this->assertSame('sales', shopAdminResolveView('orders', true, false));
        $this->assertSame('orders', shopAdminResolveView('sales', false, true));
        $this->assertSame('orders', shopAdminResolveView('orders', false, true));
        $this->assertNull(shopAdminResolveView('sales', false, false));

        $this->assertTrue(shopAdminActionAllowed('save_sales', true, false));
        $this->assertTrue(shopAdminActionAllowed('save_shipping', true, false));
        $this->assertFalse(shopAdminActionAllowed('order_ship', true, false));
        $this->assertFalse(shopAdminActionAllowed('refund_create', true, false));
        $this->assertTrue(shopAdminActionAllowed('order_ship', false, true));
        $this->assertTrue(shopAdminActionAllowed('refund_create', false, true));
        $this->assertFalse(shopAdminActionAllowed('save_sales', false, true));
        $this->assertFalse(shopAdminActionAllowed('unknown', true, true));
    }

    /** 旧核心先加载插件、后加载菜单 API 时，商城仍须在后台自行完成菜单注册。 */
    public function testShopRegistersAdminMenuWithLegacyBootstrapOrder(): void
    {
        $process = proc_open(
            [PHP_BINARY, ROOT_PATH . '/tests/fixtures/shop-admin-menu-probe.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('', $errors);

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['api_loaded'] ?? false);
        $this->assertSame('product', $result['registration']['group'] ?? null);
        $this->assertSame('shop_sales', $result['registration']['item']['key'] ?? null);
        $this->assertSame('/admin/plugin_page.php?plugin=shop', $result['registration']['item']['url'] ?? null);
        $this->assertSame('shop_orders', $result['order_registration']['item']['key'] ?? null);
        $this->assertSame('shop_orders', $result['order_registration']['item']['perm'] ?? null);
    }

    /** 七张表双方言齐备、MySQL 侧带 5.7 底线的字符集、占位符可替换。 */
    public function testShopTableSchemasCoverSevenTablesInBothDialects(): void
    {
        $schemas = shopTableSchemas();
        $this->assertSame(
            ['shop_products', 'shop_orders', 'shop_order_items', 'shop_payments',
             'shop_payment_notifications', 'shop_refunds', 'shop_member_addresses'],
            array_keys($schemas),
            '表清单与立项报告 §四一致（增表必须是有意为之）'
        );
        foreach ($schemas as $name => $schema) {
            $this->assertStringContainsString('{p}', $schema['mysql'], "{$name} mysql 用占位符");
            $this->assertStringContainsString('{p}', $schema['sqlite'], "{$name} sqlite 用占位符");
            $this->assertStringContainsString('IF NOT EXISTS', $schema['mysql'], "{$name} 幂等");
            $this->assertStringContainsString('utf8mb4_general_ci', $schema['mysql'], "{$name} MySQL 5.7 字符集底线");
            $this->assertStringNotContainsString('AUTO_INCREMENT=', $schema['mysql'], "{$name} 不锁自增起点（多站部署）");
        }
        // 关键幂等约束真的在 DDL 里（回调幂等第二道闸）
        $this->assertStringContainsString('UNIQUE KEY `uk_notify_hash`', $schemas['shop_payment_notifications']['mysql']);
        $this->assertStringContainsString('uk_gateway_trade', $schemas['shop_payments']['mysql']);
    }

    /** G2 插件 schema 升级通道：步骤表版本递增、覆盖当前版本、首步为初始建表。 */
    public function testShopSchemaUpgradePathIsIncrementalAndIdempotent(): void
    {
        $steps = shopSchemaSteps();
        $versions = array_column($steps, 0);
        $this->assertSame($versions, array_values(array_unique($versions)), '步骤版本单调递增不重复');
        $this->assertSame(shopSchemaVersion(), max($versions), '最高步骤版本等于当前结构版本');
        $this->assertSame(1, $steps[0][0], '第一步必须是初始建表');
        // v2 步骤：订单表加物流列（发货信息是商家/买家的共同契约）
        $tracking = array_values(array_filter($steps, static fn(array $s): bool => $s[0] === 2));
        $this->assertCount(1, $tracking);
        $this->assertStringContainsString('订单表增加物流单号', $tracking[0][1]);
    }
}
