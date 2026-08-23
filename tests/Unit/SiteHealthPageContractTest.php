<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteHealthPageContractTest extends TestCase
{
    public function testPageIsSuperAdminOnlyAndPostActionsRequireCsrf(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_health.php');

        self::assertStringContainsString("requirePermission('*')", $page);
        self::assertStringContainsString('verifyCsrf();', $page);
        self::assertStringContainsString("post('action')", $page);
        self::assertStringNotContainsString("post('url')", $page);
        self::assertStringContainsString("settingModel()->saveBatch([", $page);
        self::assertStringNotContainsString("site_health_last_results", $page);
        self::assertGreaterThanOrEqual(3, substr_count($page, 'SiteHealth::cleanupBrowserProbe('));
    }

    public function testBrowserTargetsAreFixedAndSameOrigin(): void
    {
        $health = (string) file_get_contents(ROOT_PATH . '/includes/SiteHealth.php');
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_health.php');

        self::assertStringContainsString("'/config/site-health-probe.php'", $health);
        self::assertStringContainsString("'/includes/site-health-probe.php'", $health);
        self::assertStringContainsString("'/storage/'", $health);
        self::assertStringContainsString("DB_PREFIX . 'users'", $health);
        self::assertStringNotContainsString("DB_PREFIX . 'admin_users'", $health);
        self::assertStringContainsString('CURLINFO_RESPONSE_CODE', $health);
        self::assertStringContainsString('httpStatusCode($http_response_header ?? [])', $health);
        self::assertStringContainsString("credentials: 'same-origin'", $page);
        self::assertStringContainsString("redirect: 'manual'", $page);
        self::assertStringContainsString('body.slice(0, 1024)', $page);
    }

    public function testFeatureIsDiscoverableFromMenuDashboardAndCli(): void
    {
        $menu = (string) file_get_contents(ROOT_PATH . '/admin/includes/sidebar_menu.php');
        $dashboard = (string) file_get_contents(ROOT_PATH . '/admin/index.php');
        $command = (string) file_get_contents(ROOT_PATH . '/includes/commands/site_health.php');

        self::assertStringContainsString("'key'   => 'site_health'", $menu);
        self::assertStringContainsString('/admin/site_health.php', $dashboard);
        self::assertStringContainsString("CLI::register('site:health'", $command);
        self::assertStringContainsString('!empty($opts[\'remote\'])', $command);
    }
}
