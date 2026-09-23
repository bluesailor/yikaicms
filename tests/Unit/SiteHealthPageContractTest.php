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

    public function testFeatureIsDiscoverableFromMenuAndCli(): void
    {
        $menu = (string) file_get_contents(ROOT_PATH . '/admin/includes/sidebar_menu.php');
        $dashboard = (string) file_get_contents(ROOT_PATH . '/admin/index.php');
        $command = (string) file_get_contents(ROOT_PATH . '/includes/commands/site_health.php');
        $header = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        $healthPage = (string) file_get_contents(ROOT_PATH . '/admin/site_health.php');

        self::assertStringContainsString("'key'   => 'site_health'", $menu);
        self::assertStringContainsString("CLI::register('site:health'", $command);
        self::assertStringContainsString('!empty($opts[\'remote\'])', $command);
        self::assertStringContainsString('data-testid="admin-help-link"', $header);
        // 顶栏图标指向使用教程（仅中文版）；英/日后台与伪静态专项说明共用 adminHelpUrl()，控制台提醒与体检页也调它
        self::assertStringContainsString("default => 'https://www.yikaicms.com/tutorial.php',", $header);
        self::assertStringContainsString("'en', 'ja' => adminHelpUrl(),", $header);
        self::assertStringContainsString('<a href="<?php echo e($adminTutorialUrl); ?>"', $header);
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString('https://www.yikaicms.com/en/#help', $functions);
        self::assertStringContainsString('https://www.yikaicms.com/ja/#help', $functions);
        self::assertStringContainsString('<?php echo e(adminHelpUrl()); ?>', $dashboard);
        self::assertStringContainsString('<?php echo e(adminHelpUrl()); ?>', $healthPage);
        self::assertStringContainsString('rel="noopener noreferrer"', $header);
        self::assertStringContainsString('data-testid="site-health-rewrite-help"', $healthPage);

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertArrayHasKey('admin_help_rewrite', $strings);
        }
    }

    public function testFreshInstallShowsOneTimeRewriteOnboardingOnlyForNewSites(): void
    {
        $dashboard = (string) file_get_contents(ROOT_PATH . '/admin/index.php');
        $installer = (string) file_get_contents(ROOT_PATH . '/install/index.php');
        $defaults = (string) file_get_contents(ROOT_PATH . '/config/defaults.php');

        self::assertStringContainsString("post('action') === 'dismiss_rewrite_onboarding'", $dashboard);
        self::assertStringContainsString("settingModel()->saveBatch(['onboarding_rewrite_dismissed' => '1'])", $dashboard);
        self::assertStringContainsString("config('onboarding_rewrite_dismissed', '1') === '0'", $dashboard);
        self::assertStringContainsString('data-testid="rewrite-onboarding-notice"', $dashboard);
        self::assertStringContainsString('data-testid="rewrite-onboarding-help"', $dashboard);
        self::assertStringContainsString('data-testid="rewrite-onboarding-dismiss"', $dashboard);
        self::assertStringContainsString("'onboarding_rewrite_dismissed' => ['value' => '1'", $defaults);
        self::assertStringContainsString("'onboarding_rewrite_dismissed', '0'", $installer);

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertArrayHasKey('onb_rewrite_title', $strings);
            self::assertArrayHasKey('onb_rewrite_body', $strings);
            self::assertArrayHasKey('onb_rewrite_help', $strings);
            self::assertArrayHasKey('onb_rewrite_dismiss_failed', $strings);
        }
    }

    /**
     * 控制台不再显示站点健康提醒（2026-09-23 按产品要求移除）：体检照常在「站点健康」页与
     * 命令行里可用，只是不在登录首页打扰。「不再提醒」的开关与接口随卡片一起删除。
     */
    public function testDashboardShowsNoSiteHealthNotice(): void
    {
        $dashboard = (string) file_get_contents(ROOT_PATH . '/admin/index.php');
        $defaults = (string) file_get_contents(ROOT_PATH . '/config/defaults.php');

        foreach (['dashboard-health-notice', 'dismiss_site_health_notice', 'site_health_last_summary', 'dashboard_site_health_dismissed'] as $marker) {
            self::assertStringNotContainsString($marker, $dashboard, $marker);
        }
        self::assertStringNotContainsString("'dashboard_site_health_dismissed'", $defaults);
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertSame([], array_values(array_filter(array_keys($strings), static fn($k) => str_starts_with((string) $k, 'dashboard_health_'))), $lang);
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

    public function testAccessibilityCategoryAndFrontendBaselineAreWired(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_health.php');
        $health = (string) file_get_contents(ROOT_PATH . '/includes/SiteHealth.php');
        $themeHeader = (string) file_get_contents(ROOT_PATH . '/themes/default/layouts/header.php');
        $fallbackHeader = (string) file_get_contents(ROOT_PATH . '/includes/header.php');
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/src/app.css');

        self::assertStringContainsString("'accessibility' => __('health_category_accessibility')", $page);
        self::assertStringContainsString('checkAccessibilityContrast()', $health);
        self::assertStringContainsString('checkAccessibilityTheme($root)', $health);
        self::assertStringContainsString('checkAccessibilityContent()', $health);
        self::assertStringContainsString('MAX_CONTENT_ROWS + 1', $health);
        self::assertStringContainsString('href="#main-content"', $themeHeader);
        self::assertStringContainsString('id="main-content" tabindex="-1"', $themeHeader);
        self::assertStringContainsString('href="#main-content"', $fallbackHeader);
        self::assertStringContainsString('[tabindex]:not([tabindex="-1"])', $css);

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertArrayHasKey('skip_to_content', $strings);
            self::assertArrayHasKey('health_category_accessibility', $strings);
            self::assertArrayHasKey('health_a11y_content_bad', $strings);
        }
    }
}
