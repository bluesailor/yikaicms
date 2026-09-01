<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeSettingsPlacementTest extends TestCase
{
    public function testTemplateAppearanceSettingsAreHiddenFromGeneralSettings(): void
    {
        $settings = (string) file_get_contents(ROOT_PATH . '/admin/setting.php');
        $hiddenStart = strpos($settings, '$hiddenKeys = [');
        self::assertNotFalse($hiddenStart);
        $hiddenEnd = strpos($settings, '];', (int) $hiddenStart);
        self::assertNotFalse($hiddenEnd);
        $hiddenBlock = substr($settings, (int) $hiddenStart, (int) $hiddenEnd - (int) $hiddenStart);

        foreach ([
            'primary_color',
            'secondary_color',
            'theme_color_profiles',
            'banner_height_pc',
            'banner_height_mobile',
            'banner_fullscreen',
        ] as $key) {
            self::assertStringContainsString(
                "'{$key}'",
                $hiddenBlock,
                $key . ' must not be rendered by admin/setting.php'
            );
        }
    }

    public function testThemePageOwnsTemplateSettingsWithoutRenamingStoredKeys(): void
    {
        $theme = (string) file_get_contents(ROOT_PATH . '/admin/theme.php');

        self::assertStringContainsString("'save_theme_settings'", $theme);
        self::assertStringContainsString('data-testid="theme-settings-tab"', $theme);
        self::assertStringContainsString('data-testid="theme-settings-panel"', $theme);
        self::assertStringContainsString('verifyCsrf();', $theme);
        self::assertStringContainsString("do_action('data_changed');", $theme);

        foreach ([
            'primary_color',
            'secondary_color',
            'theme_color_profiles',
            'banner_height_pc',
            'banner_height_mobile',
            'banner_fullscreen',
        ] as $key) {
            self::assertStringContainsString("'{$key}'", $theme);
        }
    }

    public function testTemplateSettingsValidateColorsAndBoundSliderHeights(): void
    {
        $theme = (string) file_get_contents(ROOT_PATH . '/admin/theme.php');

        self::assertStringContainsString("preg_match('/^#[0-9A-F]{6}$/D'", $theme);
        self::assertStringContainsString("max(200, min(1000, postInt('banner_height_pc', 650)))", $theme);
        self::assertStringContainsString("max(150, min(600, postInt('banner_height_mobile', 300)))", $theme);
    }

    public function testThemeColorsAreStoredPerTemplateAndRestoredOnActivation(): void
    {
        $theme = (string) file_get_contents(ROOT_PATH . '/admin/theme.php');

        self::assertStringContainsString('ThemePalette::profiles', $theme);
        self::assertStringContainsString('ThemePalette::encodeProfiles', $theme);
        self::assertStringContainsString("ThemePalette::definition(ROOT_PATH . '/themes'", $theme);
        self::assertStringContainsString('theme_factory_palette', $theme);
    }
}
