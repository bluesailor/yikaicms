<?php
/**
 * 页面目录卡片视图：已停用页的明晰外观、就地恢复/删除与排序契约。
 *
 * 删除红线与列表视图一致：仅已停用、非系统页、非相册、且持 delete_page 权限。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// 与 BloxStoredXssTest 的全局桩同一约定（套件内先加载者生效）：null = 放行全部
if (!function_exists('hasPermission')) {
    function hasPermission(string $permission): bool
    {
        $perms = $GLOBALS['_test_admin_perms'] ?? null;
        if ($perms === null) {
            return true;
        }
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }
}

final class WebsitePagesDisabledCardsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        require_once ROOT_PATH . '/admin/includes/website_pages.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_test_admin_perms'] = ['delete_page', 'blox_edit', 'edit_page'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_admin_perms'] = null;
    }

    private static function page(array $overrides = []): array
    {
        return array_merge([
            'id' => 42, 'name' => '关于我们', 'lang' => 'zh-CN', 'type' => 'page', 'is_system' => 0, 'is_nav' => 1,
            'parent_name' => '', 'status' => 1, 'publication' => 'published', 'page_kind' => 'page',
            'redirected' => false, 'edit_target' => ['name' => ''], 'edit_url' => '/admin/blox_editor.php?id=42',
            'can_edit' => true, 'modified_at' => 1789500000, 'public_url' => '/page.php?id=42',
        ], $overrides);
    }

    public function testDisabledCardIsClearlyMarkedWithInlineRestoreAndDelete(): void
    {
        $html = renderWebsitePageCard(self::page(['status' => 0]));

        self::assertStringContainsString('website-page-card is-disabled', $html);
        self::assertStringContainsString('data-page-disabled="1"', $html);
        self::assertStringContainsString('is-off', $html, '停用徽标使用醒目样式');
        self::assertStringContainsString('ti-eye-off', $html, '徽标带停用图标');
        self::assertStringContainsString('data-testid="page-restore-42"', $html);
        self::assertStringContainsString('data-testid="page-delete-42"', $html);
        // 恢复/删除已提到卡面："…"菜单不再重复这两项（删除入口全卡片只此一处）
        self::assertSame(1, substr_count($html, 'data-page-delete='));
        self::assertSame(1, substr_count($html, 'toggleStatus(42'));
        // 停用页前台 404：不给浏览入口；启用页照常有
        self::assertStringNotContainsString('/page.php?id=42" target="_blank"', $html);
        self::assertStringContainsString('/page.php?id=42" target="_blank"', renderWebsitePageCard(self::page()));
    }

    public function testDeleteKeepsTheSameRedLinesAsTheListView(): void
    {
        // 系统页：可恢复，不可删除
        $system = renderWebsitePageCard(self::page(['status' => 0, 'is_system' => 1]));
        self::assertStringContainsString('data-testid="page-restore-42"', $system);
        self::assertStringNotContainsString('data-page-delete=', $system);

        // 相册：既不就地恢复也不删除（沿用原有相册规则）
        $album = renderWebsitePageCard(self::page(['status' => 0, 'type' => 'album', 'page_kind' => 'album']));
        self::assertStringNotContainsString('page-restore-42', $album);
        self::assertStringNotContainsString('data-page-delete=', $album);

        // 无 delete_page 权限：可恢复，不可删除
        $GLOBALS['_test_admin_perms'] = ['blox_edit', 'edit_page'];
        $limited = renderWebsitePageCard(self::page(['status' => 0]));
        self::assertStringContainsString('data-testid="page-restore-42"', $limited);
        self::assertStringNotContainsString('data-page-delete=', $limited);
    }

    public function testActiveCardKeepsDisableInsideTheMoreMenuOnly(): void
    {
        $html = renderWebsitePageCard(self::page());

        self::assertStringNotContainsString('is-disabled', $html);
        self::assertStringNotContainsString('page-restore-42', $html);
        self::assertStringNotContainsString('data-page-delete=', $html);
        self::assertSame(1, substr_count($html, 'toggleStatus(42'), '启用页仍可在"…"菜单里停用');
        self::assertStringContainsString('is-live', $html);
    }

    public function testOverviewCardsStayReadOnly(): void
    {
        $html = renderWebsitePageCard(self::page(['status' => 0]), true);
        self::assertStringContainsString('is-disabled', $html, '概览卡仍标明停用');
        self::assertStringNotContainsString('page-restore-42', $html);
        self::assertStringNotContainsString('data-page-delete=', $html);
    }

    public function testCardsViewOrdersDisabledPagesAfterActiveOnes(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/page.php');
        $active = strpos($source, 'foreach ($pages as $page) { echo renderWebsitePageCard($page); }');
        $divider = strpos($source, 'website-pages-disabled-divider');
        $hidden = strpos($source, 'foreach ($hiddenPages as $page) { echo renderWebsitePageCard($page); }');
        self::assertNotFalse($active);
        self::assertNotFalse($divider);
        self::assertNotFalse($hidden);
        self::assertTrue($active < $divider && $divider < $hidden, '卡片视图必须先启用页、后分隔条、再停用页');
        self::assertStringContainsString("__('website_pages_disabled_section'", $source);
    }
}
