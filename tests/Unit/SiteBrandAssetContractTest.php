<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteBrandAssetContractTest extends TestCase
{
    public function testSettingsWarnInsteadOfRenderingBrokenImagePreview(): void
    {
        $settings = (string) file_get_contents(ROOT_PATH . '/admin/setting.php');

        self::assertStringContainsString('SiteAsset::inspect((string) $item[\'value\'])', $settings);
        self::assertStringContainsString('data-testid="setting-image-resource-warning"', $settings);
        self::assertStringContainsString('$item[\'value\'] && $__imageCanPreview', $settings);
        self::assertStringContainsString('clearImageResourceWarning(currentImageKey)', $settings);
        self::assertStringContainsString('clearImageResourceWarning(key)', $settings);
    }

    public function testFrontendAndBloxShareTheSiteAssetAvailabilityRule(): void
    {
        $themeHeader = (string) file_get_contents(ROOT_PATH . '/themes/default/layouts/header.php');
        $legacyHeader = (string) file_get_contents(ROOT_PATH . '/includes/header.php');
        $adminHeader = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        $logoElement = (string) file_get_contents(ROOT_PATH . '/includes/builder/elements/LogoElement.php');

        self::assertStringContainsString('SiteAsset::availableUrl', $themeHeader);
        self::assertStringContainsString('SiteAsset::availableUrl', $legacyHeader);
        self::assertStringContainsString('SiteAsset::availableUrl', $adminHeader);
        self::assertStringContainsString('SiteAsset::availableUrl($set)', $functions);
        self::assertStringContainsString('SiteAsset::availableUrl', $logoElement);
        self::assertStringNotContainsString('availableLogoUrl', $logoElement);
    }
}
