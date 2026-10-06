<?php
/** v2.1 按能力授权：每个核心能力都声明权限键与确认档位；没登录、缺权限键都拿不到能力，也不出现在交给模型的工具清单里。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Abilities;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

// 独立进程：hasPermission 桩函数只能定义一次，同进程里别的测试会先定义成「全都放行」
#[RunTestsInSeparateProcesses]
final class AbilitiesPermissionContractTest extends TestCase
{
    /** @var list<string> */
    private static array $granted = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('hasPermission')) {
            eval('function hasPermission(string $p): bool { return in_array($p, \Yikai\Tests\Unit\AbilitiesPermissionContractTest::granted(), true) || in_array("*", \Yikai\Tests\Unit\AbilitiesPermissionContractTest::granted(), true); }');
        }
        require_once ROOT_PATH . '/includes/permissions.php';
        require_once ROOT_PATH . '/includes/Abilities.php';
        require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
        require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id']);
        self::$granted = [];
        parent::tearDown();
    }

    /** @return list<string> */
    public static function granted(): array
    {
        return self::$granted;
    }

    public function testEveryCoreAbilityDeclaresPermissionKeysAndATier(): void
    {
        foreach (Abilities::catalog() as $ability) {
            self::assertIsArray(Abilities::get($ability['name'])['permissions'], $ability['name'] . ' 必须声明 permissions（空数组也要显式写）');
            self::assertContains($ability['tier'], Abilities::TIERS, $ability['name']);
            if ($ability['mutating'] && $ability['tier'] === 'read') {
                self::fail($ability['name'] . ' 是写操作，不能是 read 档');
            }
        }
        $byName = array_column(Abilities::catalog(), null, 'name');
        self::assertSame(['*'], $byName['cms_update_setting']['permissions']);
        self::assertSame('confirm', $byName['cms_publish_content']['tier']);
        self::assertSame('draft', $byName['cms_create_article_draft']['tier']);
    }

    public function testAbilitiesFollowTheUsersCurrentPermissions(): void
    {
        self::assertFalse(Abilities::permitted('cms_navigate_admin'), '没登录什么都不给');

        $_SESSION['admin_id'] = 7;
        self::$granted = ['edit_article'];
        self::assertTrue(Abilities::permitted('cms_navigate_admin'));
        self::assertTrue(Abilities::permitted('cms_create_article_draft'));
        self::assertTrue(Abilities::permitted('cms_search_content'));
        self::assertFalse(Abilities::permitted('cms_get_setting'), '站点设置只给超管');
        self::assertFalse(Abilities::permitted('cms_update_setting'));

        $tools = array_column(array_column(Abilities::asOpenAITools(), 'function'), 'name');
        self::assertContains('cms_create_article_draft', $tools);
        self::assertNotContains('cms_update_setting', $tools, '没权限的能力不交给模型');

        self::$granted = ['edit_product'];
        self::assertFalse(Abilities::permitted('cms_create_article_draft'), '降权后能力随之收回');
        self::assertSame('Permission denied', Abilities::execute('cms_create_article_draft', ['title' => 'x'])['error']);
    }
}
