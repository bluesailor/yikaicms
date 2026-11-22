<?php
/**
 * 产品导入的权限规则（2.3.0）：导入、导出、撤销、新建分类、往媒体库加图五个动作分开定义、分开检查。
 * 页面入口本身只放超级管理员（所有者 2026-11-20 定）；这里测的是入口以后放开时每个动作各自的把关。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/product-import/ProductImportAccess.php';

final class ProductImportAccessTest extends TestCase
{
    /** @param list<string> $keys 这个账号有的权限键；含 '*' 即超级管理员 @return callable(string):bool */
    private static function account(array $keys): callable
    {
        return static fn (string $key): bool => in_array('*', $keys, true) || in_array($key, $keys, true);
    }

    /** @return array<string,array{0:list<string>,1:array<string,bool>}> 账号 => [权限键, 动作 => 是否允许] */
    public static function accounts(): array
    {
        $none = array_fill_keys(ProductImportAccess::ACTIONS, false);
        return [
            'no permissions' => [[], $none],
            'article editor' => [['edit_article', 'delete_article'], $none],
            'media manager only' => [['media'], $none],
            'can delete products but not edit' => [['delete_product'], $none],
            'product editor' => [['edit_product'], ['import' => true, 'export' => true, 'create_category' => true] + $none],
            'product editor who may delete' => [['edit_product', 'delete_product'], ['import' => true, 'export' => true, 'undo' => true, 'create_category' => true] + $none],
            'product editor with media library' => [['edit_product', 'media'], ['import' => true, 'export' => true, 'create_category' => true, 'create_media' => true] + $none],
            'product editor with delete and media' => [['edit_product', 'delete_product', 'media'], array_fill_keys(ProductImportAccess::ACTIONS, true)],
            'super admin' => [['*'], array_fill_keys(ProductImportAccess::ACTIONS, true)],
        ];
    }

    /**
     * @dataProvider accounts
     * @param list<string> $keys @param array<string,bool> $expected
     */
    public function testEachActionIsDecidedOnItsOwn(array $keys, array $expected): void
    {
        $has = self::account($keys);
        foreach (ProductImportAccess::ACTIONS as $action) {
            self::assertSame($expected[$action], ProductImportAccess::can($action, $has), $action);
        }
    }

    public function testEveryActionIsDefinedAndUnknownOnesAreRefused(): void
    {
        self::assertSame(ProductImportAccess::ACTIONS, array_keys(ProductImportAccess::REQUIRES));
        foreach (ProductImportAccess::REQUIRES as $action => $keys) {
            self::assertNotSame([], $keys, "{$action} 至少要一项权限");
            self::assertContains('edit_product', $keys, "{$action}：没有产品编辑权的账号什么都做不了");
        }
        $super = self::account(['*']);
        self::assertFalse(ProductImportAccess::can('delete_everything', $super), '没定义的动作连超级管理员也不放');
        self::assertFalse(ProductImportAccess::handlerAllowed('pkg_secret', $super), '没登记的接口一律拒绝');
        self::assertFalse(ProductImportAccess::handlerAllowed('', $super));
        self::assertSame('', ProductImportAccess::actionFor('nope'));
    }

    public function testHandlersMapToTheRightAction(): void
    {
        $editor = self::account(['edit_product']);
        foreach (['pkg_upload', 'pkg_plan', 'job_create', 'job_run', 'job_retry', 'jobs', 'job_get', 'upload', 'import', 'sample'] as $handler) {
            self::assertSame('import', ProductImportAccess::actionFor($handler), $handler);
            self::assertTrue(ProductImportAccess::handlerAllowed($handler, $editor), $handler);
        }
        self::assertSame('export', ProductImportAccess::actionFor('pkg_export'));
        self::assertSame('undo', ProductImportAccess::actionFor('job_undo'));
        self::assertFalse(ProductImportAccess::handlerAllowed('job_undo', $editor), '只有编辑权不能撤销（撤销会删产品）');
        self::assertTrue(ProductImportAccess::handlerAllowed('job_undo', self::account(['edit_product', 'delete_product'])));
        self::assertFalse(ProductImportAccess::handlerAllowed('pkg_export', self::account(['media'])));
    }

    /** 接口文件里出现的每一个接口都必须登记在权限表里，新加接口忘了登记时这里失败 */
    public function testEveryHandlerInTheCodeIsRegistered(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/includes/product_import/package_handler.php');
        preg_match_all("/case '([a-z_]+)':/", $source, $cases);
        self::assertGreaterThanOrEqual(13, count($cases[1]));
        foreach (array_merge($cases[1], ['upload', 'import', 'sample']) as $handler) {
            self::assertArrayHasKey($handler, ProductImportAccess::HANDLERS, "接口 {$handler} 没有登记权限动作");
        }
        foreach (ProductImportAccess::HANDLERS as $handler => $action) {
            self::assertContains($action, ['import', 'export', 'undo'], $handler);
        }
    }

    public function testPlanNeedsCategoryAndMediaPermissionsOnlyWhenItWouldCreateThem(): void
    {
        $image = static fn (string $kind): array => ['cover' => [['ref' => 'a.jpg', 'kind' => $kind, 'path' => '/x/a.jpg']]];
        $row = static fn (int $index, string $action, string $kind): array => ['index' => $index, 'action' => $action, 'images' => $image($kind)];
        $category = static fn (string $action, array $errors = []): array => ['action' => $action, 'errors' => $errors];

        self::assertSame([], ProductImportAccess::planNeeds(['categories' => [$category('existing')], 'products' => [$row(0, 'update', 'site')]]),
            '分类已存在、图片已在站点上：只需要导入权限');
        self::assertSame(['create_category'], ProductImportAccess::planNeeds(['categories' => [$category('create')], 'products' => [$row(0, 'create', 'site')]]));
        self::assertSame(['create_media'], ProductImportAccess::planNeeds(['categories' => [$category('existing')], 'products' => [$row(0, 'create', 'package')]]));
        self::assertSame(['create_category', 'create_media'], ProductImportAccess::planNeeds(['categories' => [$category('create')], 'products' => [$row(0, 'create', 'package')]]));

        // 带图的那一行被跳过或有错：不会往媒体库加图
        $plan = ['categories' => [$category('existing')], 'products' => [$row(0, 'create', 'package'), $row(1, 'update', 'site')]];
        self::assertSame([], ProductImportAccess::planNeeds($plan, [0 => 'skip']));
        self::assertSame([], ProductImportAccess::planNeeds(['categories' => [], 'products' => [$row(0, 'error', 'package')]]));
        // 全部跳过：分类也不会建
        self::assertSame([], ProductImportAccess::planNeeds(['categories' => [$category('create')], 'products' => [$row(0, 'create', 'site')]], [0 => 'skip']));
        // 有错的分类不会建
        self::assertSame([], ProductImportAccess::planNeeds(['categories' => [$category('create', [['code' => 'category_incomplete']])], 'products' => [$row(0, 'create', 'site')]]));

        $needs = ['create_category', 'create_media'];
        self::assertSame(['create_media'], ProductImportAccess::missing($needs, self::account(['edit_product'])), '产品编辑能建分类，但带图导入还要媒体库权限');
        self::assertSame([], ProductImportAccess::missing($needs, self::account(['edit_product', 'media'])));
        self::assertSame($needs, ProductImportAccess::missing($needs, self::account(['media'])));
        self::assertSame([], ProductImportAccess::missing($needs, self::account(['*'])));
    }

    /** 这一个检查点：页面入口仍只放超级管理员；接口在进各自的处理文件之前先过权限表；建任务和分批执行都查分类 / 媒体权限 */
    public function testEntryStaysSuperAdminOnlyAndHandlersAreGuarded(): void
    {
        $entry = (string) file_get_contents(ROOT_PATH . '/admin/product_import.php');
        self::assertStringContainsString("requirePermission('*');", $entry);
        self::assertStringNotContainsString("requirePermission('edit_product')", $entry);
        self::assertMatchesRegularExpression("/'key'\\s*=>\\s*'product_import',\\s*'perm'\\s*=>\\s*'\\*'/", (string) file_get_contents(ROOT_PATH . '/admin/includes/sidebar_menu.php'));
        // 侧栏分组标题链接到组里第一项：导入要排在产品管理（默认 100）之后（2.3.0 候选验收发现）
        self::assertMatchesRegularExpression("/'key'\\s*=>\\s*'product_import',[^\\]]*'priority'\\s*=>\\s*1[0-9]{2},/s", (string) file_get_contents(ROOT_PATH . '/admin/includes/sidebar_menu.php'));
        self::assertStringContainsString("hasPermission('*')): ?><a href=\"<?php echo e(BasePath::url('/admin/product_import.php'))", (string) file_get_contents(ROOT_PATH . '/admin/product.php'));

        $page = (string) file_get_contents(ROOT_PATH . '/admin/includes/product_import/page.php');
        $guard = strpos($page, 'ProductImportAccess::handlerAllowed($handler, $pimpHas)');
        self::assertNotFalse($guard);
        foreach (['package_handler.php', 'upload_handler.php', 'import_handler.php', "'sample'"] as $later) {
            self::assertGreaterThan($guard, strpos($page, $later), "{$later} 必须在权限检查之后");
        }

        $handlers = (string) file_get_contents(ROOT_PATH . '/admin/includes/product_import/package_handler.php');
        $create = strpos($handlers, "case 'job_create':");
        $run = strpos($handlers, "case 'job_run':");
        self::assertGreaterThan($create, strpos($handlers, 'ProductImportAccess::planNeeds($plan, $decisions)'));
        self::assertLessThan($run, strpos($handlers, 'ProductImportAccess::planNeeds($plan, $decisions)'));
        self::assertSame(2, substr_count($handlers, "ProductImportAccess::missing("), '建任务时查一次，分批执行时再查一次');
        self::assertStringContainsString("\$job['needs'] ?? ['create_category', 'create_media']", $handlers, '升级前建的任务按两项都需要处理');
    }
}
