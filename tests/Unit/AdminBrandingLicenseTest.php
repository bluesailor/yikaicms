<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/License.php';

/** 后台品牌：自基本设置独立成页，自定义限注册码授权站点，未授权显示出厂品牌。 */
final class AdminBrandingLicenseTest extends TestCase
{
    public function testOnlyLicensedSitesMayCustomizeAdminBranding(): void
    {
        self::assertTrue(\license_allows_admin_branding(['valid' => true, 'plan' => 'basic', 'modules' => []]));
        // 服务期到期不收回：持有付费模块仍可用
        self::assertTrue(\license_allows_admin_branding(['valid' => false, 'expired' => true, 'modules' => ['seo-pro']]));
        self::assertFalse(\license_allows_admin_branding(\license_free('no_key')));
        self::assertFalse(\license_allows_admin_branding(['valid' => false, 'reason' => 'domain_mismatch', 'modules' => []]));
        self::assertFalse(\license_allows_admin_branding(['valid' => false, 'modules' => ['', 0, null]]));
    }

    public function testBrandKeysMovedOutOfBasicSettingsPage(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("define('ADMIN_BRAND_SETTING_KEYS', ['admin_title', 'admin_copyright', 'admin_logo', 'admin_logo_max_height']);", $functions);
        $setting = (string) file_get_contents(ROOT_PATH . '/admin/setting.php');
        self::assertStringContainsString('...ADMIN_BRAND_SETTING_KEYS,', $setting, '基本设置页不再渲染后台品牌');
        self::assertMatchesRegularExpression('/foreach \(ADMIN_BRAND_SETTING_KEYS as \$key\) \{\s*unset\(\$settings\[\$key\]\);/', $setting, '基本设置页不再接受后台品牌写入');

        $menu = (string) file_get_contents(ROOT_PATH . '/admin/includes/sidebar_menu.php');
        self::assertStringContainsString("'url'   => '/admin/admin_brand.php'", $menu);
    }

    public function testStandalonePageRejectsUnlicensedSaves(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/admin_brand.php');
        self::assertStringContainsString("requirePermission('*');", $page);
        self::assertMatchesRegularExpression('/verifyCsrf\(\);\s*if \(!\$brandAllowed\) \{\s*error\(__\(\'admin_brand_license_required\'\), 403\);/', $page);
        self::assertStringContainsString('$brandAllowed = adminBrandingCustomizable();', $page);
    }

    public function testAdminChromeFallsBackToDefaultBrandWithoutLicense(): void
    {
        $header = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        $footer = (string) file_get_contents(ROOT_PATH . '/admin/includes/footer.php');
        self::assertStringContainsString('$adminLogo = adminBrandLogoUrl();', $header);
        self::assertStringNotContainsString("config('admin_logo'", $header);
        self::assertStringContainsString('$adminCopyright = adminBrandCopyright();', $footer);
        self::assertStringNotContainsString("config('admin_copyright'", $footer);

        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertMatchesRegularExpression('/function adminBrandName\(\): string\s*\{\s*if \(!adminBrandingCustomizable\(\)\) \{\s*return ADMIN_BRAND_DEFAULT_NAME;/', $functions);

        $ability = (string) file_get_contents(ROOT_PATH . '/includes/abilities/cms_admin.php');
        self::assertStringContainsString('in_array($key, ADMIN_BRAND_SETTING_KEYS, true) && !adminBrandingCustomizable()', $ability);
    }
}
