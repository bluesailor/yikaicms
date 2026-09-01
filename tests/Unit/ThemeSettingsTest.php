<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ThemeSettings.php';

final class ThemeSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    public function testNormalizeClampsValuesAndRejectsUnsafeColors(): void
    {
        $settings = ThemeSettings::normalize([
            'general' => ['site_layout' => 'invalid', 'content_max_width' => 99999, 'site_background' => 'red', 'color_mode' => 'auto'],
            'typography' => ['html_font_size' => 1],
            'responsive' => ['mobile' => 1],
        ]);

        self::assertSame('full', $settings['general']['site_layout']);
        self::assertSame(1920, $settings['general']['content_max_width']);
        self::assertSame('#F8FAFC', $settings['general']['site_background']);
        self::assertSame('auto', $settings['general']['color_mode']);
        self::assertSame(14, $settings['typography']['html_font_size']);
        self::assertSame(480, $settings['responsive']['mobile']);
    }

    public function testReadUsesLegacyColorsForButtonDefaults(): void
    {
        $GLOBALS['_test_config'] = [
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
        ];

        $settings = ThemeSettings::read();

        self::assertSame('#112233', $settings['button']['background']);
        self::assertSame('#445566', $settings['button']['hover_background']);
    }

    public function testCssContainsGlobalVariablesAndResponsiveRules(): void
    {
        $GLOBALS['_test_config'] = [
            ThemeSettings::KEY => json_encode([
                'general' => ['content_max_width' => 1280],
                'responsive' => ['mobile' => 700],
            ], JSON_THROW_ON_ERROR),
        ];

        $css = ThemeSettings::css();

        self::assertStringContainsString('--yk-content-max-width:1280px', $css);
        self::assertStringContainsString('@media (max-width:700px)', $css);
        self::assertStringContainsString('html{font-size:var(--yk-html-font-size)', $css);
    }

    public function testEmptySettingsDoNotOverrideThemeFactoryAppearance(): void
    {
        $GLOBALS['_test_config'] = [];

        self::assertSame('', ThemeSettings::css());
    }

    public function testStoredProfileIsNormalizedBeforeCssGeneration(): void
    {
        $GLOBALS['_test_config'][ThemeSettings::KEY] = json_encode([
            'themes' => ['default' => [
                'general' => ['content_max_width' => 99999, 'site_background' => 'red', 'color_mode' => 'garbage'],
                'typography' => ['body_font' => 'Arial;}</style><script>'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $settings = ThemeSettings::read('default');
        self::assertSame(1920, $settings['general']['content_max_width']);
        self::assertSame('#F8FAFC', $settings['general']['site_background']);
        self::assertSame('light', $settings['general']['color_mode']);
        self::assertSame('system', $settings['typography']['body_font']);
    }

    public function testProfilesStaySeparatedByThemeAndMigrateLegacyFlatData(): void
    {
        $stored = ThemeSettings::encodeProfile('minimal', ThemeSettings::normalize([
            'general' => ['content_max_width' => 960],
        ]));
        $GLOBALS['_test_config'][ThemeSettings::KEY] = $stored;

        self::assertSame(960, ThemeSettings::read('minimal')['general']['content_max_width']);
        self::assertSame(1200, ThemeSettings::read('business')['general']['content_max_width']);
    }

    public function testCustomCssCannotCloseRuntimeStyleTag(): void
    {
        $GLOBALS['_test_config'][ThemeSettings::KEY] = json_encode([
            'themes' => ['default' => ['custom_css' => '</style><script>alert(1)</script>']],
        ], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('</style><script>', ThemeSettings::css());
    }
}
