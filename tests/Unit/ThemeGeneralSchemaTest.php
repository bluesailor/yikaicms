<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ThemeSettings.php';

final class ThemeGeneralSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    public function testDefinitionsOwnDefaultsRangesAndOutputNames(): void
    {
        $fields = ThemeSettings::generalFields();
        self::assertCount(8, $fields);
        foreach ($fields as $key => $field) {
            self::assertSame($field['default'], ThemeSettings::defaults()['general'][$key]);
            self::assertSame('*', $field['permission']);
            self::assertSame('theme', $field['scope']);
            self::assertArrayHasKey('depends_on', $field);
            self::assertStringStartsWith('theme_settings_', $field['label']);
            self::assertTrue(isset($field['css']) || isset($field['output']));
        }
        self::assertSame('--yk-content-max-width', $fields['content_max_width']['css']);
        self::assertSame(['color_mode' => ['light', 'auto']], $fields['site_background']['depends_on']);
    }

    public function testStrictWriteRejectsInvalidValuesInsteadOfSilentlyClamping(): void
    {
        $defaults = ThemeSettings::defaults()['general'];
        foreach ([759, 1921, '1200px', '1e3', '1200.0', 1200.5, false, [], '99999999999999999999999'] as $value) {
            $result = ThemeSettings::validateGeneral(['content_max_width' => $value], $defaults);
            self::assertArrayHasKey('content_max_width', $result['errors']);
        }
        foreach (['red', '#123', '#FFFFFF;display:none', '</style><script>', []] as $value) {
            self::assertArrayHasKey('site_background', ThemeSettings::validateGeneral(['site_background' => $value], $defaults)['errors']);
        }
        foreach ([['site_layout' => 'anything'], ['color_mode' => ['dark']], ['css' => 'background:url(x)'], ['callback' => 'system']] as $input) {
            self::assertNotEmpty(ThemeSettings::validateGeneral($input, $defaults)['errors']);
        }
        self::assertNotEmpty(ThemeSettings::validateGeneral('invalid', $defaults)['errors']);
    }

    public function testValidBoundariesAndLegacyReadStayCompatible(): void
    {
        foreach (['760', '1920'] as $value) {
            $result = ThemeSettings::validateGeneral(['content_max_width' => $value, 'site_background' => '#abcdef'], ThemeSettings::defaults()['general']);
            self::assertSame([], $result['errors']);
            self::assertSame((int) $value, $result['values']['content_max_width']);
            self::assertSame('#ABCDEF', $result['values']['site_background']);
        }
        self::assertSame(1920, ThemeSettings::normalize(['general' => ['content_max_width' => 5000]])['general']['content_max_width']);
        self::assertSame(1200, ThemeSettings::normalize(['general' => ['content_max_width' => []]])['general']['content_max_width']);
    }

    public function testMissingAndInactiveFieldsPreserveExistingValuesButNeverBypassValidation(): void
    {
        $current = ThemeSettings::defaults()['general'];
        $current['site_background'] = '#123456';
        $current['content_max_width'] = 1440;
        $result = ThemeSettings::validateGeneral(['color_mode' => 'dark', 'site_background' => '#ABCDEF'], $current);
        self::assertSame([], $result['errors']);
        self::assertSame('#123456', $result['values']['site_background']);
        self::assertSame(1440, $result['values']['content_max_width']);
        self::assertArrayHasKey('site_background', ThemeSettings::validateGeneral(['color_mode' => 'dark', 'site_background' => 'url(x)'], $current)['errors']);
        $result = ThemeSettings::validateGeneral(['color_mode' => 'auto', 'site_background' => '#ABCDEF'], $result['values']);
        self::assertSame('#ABCDEF', $result['values']['site_background']);
    }

    public function testProfilesAndOtherSettingsSurviveAndCssUsesValidatedValues(): void
    {
        $other = ['general' => ['content_max_width' => 960], 'custom_css' => '.other{}'];
        $raw = json_encode(['schema_version' => 1, 'themes' => ['business' => $other]], JSON_THROW_ON_ERROR);
        $settings = ThemeSettings::normalize(['spacing' => ['content_gutter' => 0], 'button' => ['radius' => 0]]);
        $settings['general'] = ThemeSettings::validateGeneral(['content_max_width' => '1080', 'site_layout' => 'boxed'], $settings['general'])['values'];
        $stored = ThemeSettings::encodeProfile('default', $settings, $raw);
        self::assertSame($other, json_decode($stored, true)['themes']['business']);
        $GLOBALS['_test_config'][ThemeSettings::KEY] = $stored;
        self::assertSame(0, ThemeSettings::read('default')['button']['radius']);
        self::assertStringContainsString('--yk-content-max-width:1080px;', ThemeSettings::css());
        self::assertStringContainsString('margin-left:auto;margin-right:auto;', ThemeSettings::css());
        self::assertStringContainsString('--yk-content-gutter:0px;', ThemeSettings::css());
        self::assertSame(960, ThemeSettings::read('business')['general']['content_max_width']);
    }

    public function testPilotUsesExistingAuthenticatedSaveAndSchemaDrivenPartial(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/theme.php');
        self::assertStringContainsString("requirePermission('*');", $source);
        self::assertStringContainsString('verifyCsrf();', $source);
        self::assertStringContainsString("if (\$activeTheme === 'default')", $source);
        self::assertStringContainsString('ThemeSettings::validateGeneral(', $source);
        self::assertStringContainsString("elseif (\$themeGeneralErrors !== [])", $source);
        self::assertStringContainsString('ThemeSettings::encodeProfile($activeTheme, $styleSettings', $source);
        $partial = (string) file_get_contents(ROOT_PATH . '/admin/includes/theme_general_fields.php');
        self::assertStringContainsString('ThemeSettings::generalFields()', $partial);
        self::assertStringContainsString("\$field['depends_on']", $partial);
        self::assertStringContainsString('theme_schema_preview_hint', $partial);
    }
}
