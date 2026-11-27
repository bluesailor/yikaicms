<?php
/**
 * Enterprise content administrator next to older code paths (v2.3.2 candidate acceptance):
 * - the 2.0.x jobs/timeline permission migration must not treat an Enterprise role as unmigrated;
 * - the frontend header/footer must not carry a page-builder edit URL for an Enterprise account.
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/permissions.php';

final class EnterpriseRoleUpgradeEdgesTest extends TestCase
{
    protected function schemaSql(): array
    {
        return ['CREATE TABLE roles (id INTEGER PRIMARY KEY, status INTEGER, permissions TEXT)'];
    }

    /** @return array{check: callable(): bool, php: callable(): string} */
    private function migration(): array
    {
        return require ROOT_PATH . '/migrations/20260730_job_timeline_perms.php';
    }

    public function testEnterpriseRoleDoesNotMakeTheJobTimelineMigrationPending(): void
    {
        $enterprise = \enterpriseContentPermissions(['enterprise_case_seo']);
        db()->insert('roles', ['id' => 2, 'status' => 1, 'permissions' => json_encode($enterprise)]);
        $migration = $this->migration();

        self::assertTrue(($migration['check'])(), 'An Enterprise role carries edit_article by design and must not reopen the migration');
        ($migration['php'])();
        self::assertSame($enterprise, json_decode((string) db()->fetchColumn('SELECT permissions FROM roles WHERE id = 2'), true),
            'Running the migration must not write jobs/timeline keys into an Enterprise role');
    }

    public function testOrdinaryRolesStillGetTheJobAndTimelineKeys(): void
    {
        db()->insert('roles', ['id' => 3, 'status' => 1, 'permissions' => json_encode(['edit_article', 'delete_page'])]);
        $migration = $this->migration();

        self::assertFalse(($migration['check'])());
        ($migration['php'])();
        self::assertSame(['edit_article', 'delete_page', 'edit_job', 'edit_timeline', 'delete_timeline'],
            json_decode((string) db()->fetchColumn('SELECT permissions FROM roles WHERE id = 3'), true));
        self::assertTrue(($migration['check'])());
    }

    public function testFrontendAreaEditUrlFollowsTheSameEnterpriseRuleAsTheToolbar(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString(
            "\$editUrl = !empty(\$_SESSION['admin_id']) && !isCleanFrontendPreview() && !isEnterpriseContentAdmin()",
            $source
        );
    }
}
