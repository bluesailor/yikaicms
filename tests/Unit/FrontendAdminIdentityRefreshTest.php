<?php
/** A role changed in the backend applies to an admin who only reloads the frontend. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/permissions.php';

final class FrontendAdminIdentityRefreshTest extends TestCase
{
    private const EDITOR_ROLE = 2;
    private const ENTERPRISE_ROLE = 3;

    private mixed $savedSession = null;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role_id INTEGER, status INTEGER)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, status INTEGER, permissions TEXT)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = $_SESSION ?? null;
        db()->insert('roles', ['id' => self::EDITOR_ROLE, 'status' => 1,
            'permissions' => json_encode(['edit_page', 'blox_edit', 'edit_article'])]);
        db()->insert('roles', ['id' => self::ENTERPRISE_ROLE, 'status' => 1,
            'permissions' => json_encode(['enterprise_content', 'edit_article', 'edit_product', 'edit_case', 'enterprise_case_seo'])]);
        db()->insert('users', ['id' => 7, 'username' => 'qa-editor', 'role_id' => self::EDITOR_ROLE, 'status' => 1]);
    }

    protected function tearDown(): void
    {
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
        parent::tearDown();
    }

    public function testFrontendReloadPicksUpTheEnterpriseRoleAndBack(): void
    {
        $_SESSION = ['admin_id' => 7, 'admin_role_id' => self::EDITOR_ROLE, 'admin_permissions' => ['edit_page', 'blox_edit', 'edit_article'], 'member_id' => 5];

        db()->update('users', ['role_id' => self::ENTERPRISE_ROLE], 'id = ?', [7]);
        \refreshFrontendAdminIdentity();
        self::assertTrue(\isEnterpriseContentAdmin(), 'The frontend must stop showing editing controls without a backend visit');
        self::assertSame(self::ENTERPRISE_ROLE, $_SESSION['admin_role_id']);
        self::assertSame(['enterprise_content', 'edit_article', 'edit_product', 'edit_case', 'enterprise_case_seo'], $_SESSION['admin_permissions']);
        self::assertNotContains('blox_edit', $_SESSION['admin_permissions']);

        db()->update('users', ['role_id' => self::EDITOR_ROLE], 'id = ?', [7]);
        \refreshFrontendAdminIdentity();
        self::assertFalse(\isEnterpriseContentAdmin());
        self::assertContains('blox_edit', $_SESSION['admin_permissions'], 'Changing back restores what the new role allows');
        self::assertSame(5, $_SESSION['member_id']);
    }

    public function testEnterpriseRoleStoredWithExtraKeysIsStillNarrowed(): void
    {
        db()->update('roles', ['permissions' => json_encode(['enterprise_content', 'blox_edit', 'edit_page', '*'])], 'id = ?', [self::ENTERPRISE_ROLE]);
        db()->update('users', ['role_id' => self::ENTERPRISE_ROLE], 'id = ?', [7]);
        $_SESSION = ['admin_id' => 7, 'admin_permissions' => ['*']];

        \refreshFrontendAdminIdentity();
        self::assertSame(['enterprise_content', 'edit_article', 'edit_product', 'edit_case'], $_SESSION['admin_permissions']);
    }

    public function testDisabledAccountOnlyLosesTheAdminPartOfTheSession(): void
    {
        db()->update('users', ['status' => 0], 'id = ?', [7]);
        $_SESSION = ['admin_id' => 7, 'admin_username' => 'qa-editor', 'admin_permissions' => ['*'], 'member_id' => 5];

        \refreshFrontendAdminIdentity();
        self::assertSame(['member_id' => 5], $_SESSION);
    }

    public function testVisitorsAreNotQueried(): void
    {
        $_SESSION = ['member_id' => 5];
        \refreshFrontendAdminIdentity();
        self::assertSame(['member_id' => 5], $_SESSION);
        self::assertFalse(\syncAdminSessionIdentity());
    }

    public function testBothEntryPointsUseTheSharedSync(): void
    {
        $init = $this->source('includes/init.php');
        $frontEdit = strpos($init, "require_once ROOT_PATH . '/includes/front_edit.php';");
        $refresh = strpos($init, 'refreshFrontendAdminIdentity();');
        self::assertNotFalse($refresh);
        self::assertGreaterThan($frontEdit, $refresh);
        self::assertStringContainsString('if (!syncAdminSessionIdentity()) {', $this->source('admin/includes/auth.php'));
        self::assertStringContainsString('SupportAccess::enforce();', $this->source('admin/includes/auth.php'));
    }

    public function testDashboardSkipsThePendingFormCountForTheEnterpriseRole(): void
    {
        $dashboard = $this->source('admin/index.php');
        self::assertStringContainsString('$showFormStats = !isEnterpriseContentAdmin();', $dashboard);
        self::assertStringContainsString("'forms' => \$showFormStats ? formModel()->count(['status' => 0]) : 0,", $dashboard);
        self::assertMatchesRegularExpression("/<\\?php if \\(\\\$showFormStats\\): \\?>\\s*<div[^>]*data-testid=\"dashboard-pending-forms\"/", $dashboard);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(ROOT_PATH . '/' . $path);
        self::assertIsString($source, $path);
        return $source;
    }
}
