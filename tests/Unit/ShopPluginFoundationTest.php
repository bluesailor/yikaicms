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
                'alpha_manage' => ['label' => '甲', 'label_en' => 'Alpha'],
                'INVALID KEY' => ['label' => '应被忽略'],   // 键名不合法
                '9starts-digit' => ['label' => '应被忽略'],
            ],
            'admin_permission' => 'alpha_manage',
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
        $this->assertSame('shop_manage', $meta['admin_permission'] ?? null, '宿主页权限键必须是已声明的键');
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
}
