<?php
/** Blox role-capability boundaries across UI, entry points, and upgrade defaults. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/permissions.php';

final class BloxRolePermissionContractTest extends TestCase
{
    public function testPermissionCatalogExposesFourBloxCapabilities(): void
    {
        $permissions = $this->source('includes/permissions.php');
        $role = $this->source('admin/role.php');

        self::assertStringContainsString("return ['blox_edit', 'blox_home', 'blox_global', 'blox_code'];", $permissions);
        self::assertStringContainsString("'blox'     => ['label' => __('perm_group_blox')", $permissions);
        self::assertStringContainsString('$permCatalog = permissionCatalog();', $role);
    }

    public function testInvalidBloxPermissionCombinationsAreRejected(): void
    {
        self::assertSame(__('role_blox_edit_requires_page'), \permissionSetError(['blox_edit']));
        self::assertSame(__('role_blox_code_requires_scope'), \permissionSetError(['blox_code']));
        self::assertNull(\permissionSetError(['edit_page', 'blox_edit']));
        self::assertNull(\permissionSetError(['blox_home', 'blox_code']));
        self::assertNull(\permissionSetError(['*']));

        $role = $this->source('admin/role.php');
        self::assertStringContainsString('$permissionError = permissionSetError($permissions);', $role);
        self::assertStringContainsString('data-permission-group="<?php echo e($groupKey); ?>"', $role);
        self::assertStringContainsString('updateBloxPermissionState()', $role);
        self::assertStringContainsString("checkbox.dataset.perm === 'blox_edit'", $role);
    }

    public function testEntryPointsEnforceScenarioSpecificCapabilities(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $preview = $this->source('admin/blox_preview.php');
        $templateApi = $this->source('admin/blox_template_api.php');

        self::assertStringContainsString("requirePermission('blox_home');", $editor);
        self::assertStringContainsString("requirePermission('blox_edit');", $editor);
        self::assertStringContainsString('requireBloxTemplateTypePermission($templateType);', $editor);
        self::assertStringContainsString("\$isAreaTemplatePreview = \$isHomeLayout && in_array(\$areaPresetType, ['header', 'footer'], true);", $preview);
        self::assertStringContainsString("requirePermission('blox_global');", $preview);
        self::assertStringContainsString("requireBloxTemplateTypePermission('section');", $templateApi);

        self::assertStringContainsString("requirePermission('blox_home');", $this->source('admin/blox_home_api.php'));
        self::assertStringContainsString("requirePermission('blox_edit');", $this->source('admin/blox_page_api.php'));
        self::assertStringContainsString("requirePermission('blox_edit');", $this->source('admin/blox_contact_api.php'));
        self::assertStringContainsString("requirePermission('blox_global');", $this->source('admin/blox_design_api.php'));
        self::assertStringContainsString("requirePermission('blox_global');", $this->source('admin/blox_templates.php'));
        self::assertStringContainsString("requirePermission('blox_global');", $this->source('admin/blox_cache_api.php'));
    }

    public function testNavigationAndFrontendEditingFollowTheSameCapabilities(): void
    {
        $sidebar = $this->source('admin/includes/sidebar_menu.php');
        $adminBar = $this->source('includes/admin_bar.php');
        $frontEdit = $this->source('includes/front_edit.php');

        self::assertStringContainsString("'perm'  => 'blox_global'", $sidebar);
        self::assertStringContainsString('hasAnyBloxPermission()', $sidebar);
        self::assertStringContainsString('function adminBarCanOpenBloxUrl', $adminBar);
        self::assertStringContainsString("adminBarHasPermission('blox_home')", $adminBar);
        self::assertStringContainsString("adminBarHasPermission('blox_global')", $adminBar);
        self::assertStringContainsString("adminBarHasPermission('blox_edit') && adminBarHasPermission('edit_page')", $adminBar);
        self::assertStringContainsString('adminBarCanOpenBloxUrl($bloxEditUrl)', $frontEdit);
    }

    public function testUpgradeAndFreshInstallPreserveExistingPageEditors(): void
    {
        $migration = $this->source('migrations/20260829_blox_role_permissions.php');
        $mysql = $this->source('install/sql/mysql.sql');
        $sqlite = $this->source('install/sql/sqlite.sql');

        self::assertStringContainsString("in_array('edit_page', \$permissions, true)", $migration);
        self::assertStringContainsString("\$permissions[] = 'blox_edit';", $migration);
        self::assertStringContainsString("in_array('blox_code', \$permissions, true)", $migration);
        self::assertStringContainsString('return array_values(array_unique($permissions));', $migration);
        self::assertStringContainsString("'title_en'", $migration);
        self::assertStringContainsString("'title_ja'", $migration);
        self::assertGreaterThanOrEqual(3, substr_count($mysql, '\\"blox_edit\\"'));
        self::assertGreaterThanOrEqual(3, substr_count($sqlite, '"blox_edit"'));
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }
}
