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
        $header = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        $healthPage = (string) file_get_contents(ROOT_PATH . '/admin/site_health.php');

        self::assertStringContainsString("'key'   => 'site_health'", $menu);
        self::assertStringContainsString('/admin/site_health.php', $dashboard);
        self::assertStringContainsString("CLI::register('site:health'", $command);
        self::assertStringContainsString('!empty($opts[\'remote\'])', $command);
        self::assertStringContainsString('data-testid="admin-help-link"', $header);
        self::assertStringContainsString('https://www.yikaicms.com/en/#help', $header);
        self::assertStringContainsString('https://www.yikaicms.com/ja/#help', $header);
        self::assertStringContainsString('rel="noopener noreferrer"', $header);
        self::assertStringContainsString('data-testid="site-health-rewrite-help"', $healthPage);

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertArrayHasKey('admin_help_rewrite', $strings);
        }
    }

    public function testDashboardNoticeSupportsSessionCloseAndPersistentDismissal(): void
    {
        $dashboard = (string) file_get_contents(ROOT_PATH . '/admin/index.php');
        $defaults = (string) file_get_contents(ROOT_PATH . '/config/defaults.php');

        self::assertStringContainsString("post('action') === 'dismiss_site_health_notice'", $dashboard);
        self::assertStringContainsString("settingModel()->saveBatch(['dashboard_site_health_dismissed' => '1'])", $dashboard);
        self::assertStringContainsString("config('dashboard_site_health_dismissed', '0')", $dashboard);
        self::assertStringContainsString('verifyCsrf();', $dashboard);
        self::assertStringContainsString("requirePermission('*')", $dashboard);
        self::assertStringContainsString('data-testid="dashboard-health-dismiss"', $dashboard);
        self::assertStringContainsString('data-testid="dashboard-health-close"', $dashboard);
        self::assertStringContainsString("sessionStorage.setItem(sessionKey, '1')", $dashboard);
        self::assertStringContainsString("'dashboard_site_health_dismissed'", $defaults);

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertArrayHasKey('dashboard_health_dismiss', $strings);
            self::assertArrayHasKey('dashboard_health_close', $strings);
            self::assertArrayHasKey('dashboard_health_dismiss_failed', $strings);
        }
    }

    public function testMediaHealthUsesServerSideBoundedCursorBatches(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_health.php');
        $model = (string) file_get_contents(ROOT_PATH . '/includes/models/MediaModel.php');

        self::assertStringContainsString("\$action === 'scan_media'", $page);
        self::assertStringContainsString('MediaOptimization::MAX_BATCH', $page);
        self::assertStringContainsString("\$_SESSION['site_health_scan']['media']", $page);
        self::assertStringContainsString("\$_SESSION['site_health_scan']['created_at'] = time();", $page);
        self::assertStringNotContainsString("post('cursor')", $page);
        self::assertStringContainsString('WHERE type = ? AND id > ? ORDER BY id ASC LIMIT ?', $model);
        self::assertStringContainsString('site_health_media_summary', $page);
    }
}
