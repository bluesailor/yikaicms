<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/DeclaredSettingRows.php';

/**
 * 回归（2026-10-21，英文 Flow 整站模板 v1.0.3）：导入后约 70 个声明设置从后台设置页消失。
 * SiteTemplateData::replace() 删掉包里没带的可移植行是有意的（中文出厂文案不进模板站、撤销导入
 * 逐字节还原），错在 setting.php / setting_contact.php 按库行渲染表单。修法：设置页按 defaults.php
 * 声明补合成行，原样提交的合成值不落库。
 */
final class SiteTemplateDeclaredSettingsTest extends TestCase
{
    public function testImportedSiteKeepsEverySettingsField(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-declared-settings-probe.php') . ' 2>&1');
        self::assertSame("Settings page keeps declared fields after a site template import\n", $output);
    }

    public function testSyntheticRowsFollowDeclarationsAndHideChineseFactoryCopy(): void
    {
        $defaults = [
            'site_logo_max_height' => ['value' => '40', 'type' => 'number', 'name' => 'Logo', 'sort_order' => 5],
            'footer_copyright_text' => ['value' => '© {year} {site_name} 版权所有.', 'type' => 'text', 'name' => 'Copyright', 'sort_order' => 3],
            'footer_columns' => ['value' => '[{"title":"关于我们"}]', 'type' => 'footer_columns', 'name' => 'Columns', 'sort_order' => 1],
            'banner_fullscreen' => ['value' => '0', 'type' => 'select', 'name' => 'Full', 'options' => ['0' => 'off'], 'sort_order' => 2],
            'drifted' => ['value' => 'x', 'type' => 'text', 'name' => 'Drifted', 'sort_order' => 4],
        ];
        $rows = [['key' => 'banner_fullscreen', 'value' => '1', 'group' => 'basic', 'name' => 'Full', 'type' => 'select', 'tip' => '', 'options' => null, 'sort_order' => 2]];
        $page = DeclaredSettingRows::complete($rows, 'basic', $defaults, ['banner_fullscreen' => '1', 'drifted' => 'stored']);

        self::assertSame(['footer_columns', 'banner_fullscreen', 'footer_copyright_text', 'drifted', 'site_logo_max_height'], array_column($page, 'key'));
        $byKey = array_column($page, null, 'key');
        self::assertSame('1', $byKey['banner_fullscreen']['value']);
        self::assertArrayNotHasKey('synthetic', $byKey['banner_fullscreen']);
        self::assertSame(['40', true], [$byKey['site_logo_max_height']['value'], $byKey['site_logo_max_height']['synthetic']]);
        self::assertSame('', $byKey['footer_copyright_text']['value']);
        self::assertSame('[]', $byKey['footer_columns']['value']);
        self::assertSame(['stored', false], [$byKey['drifted']['value'], $byKey['drifted']['synthetic']], 'a row stored under another group shows its stored value');
    }

    public function testOnlyUntouchedSyntheticValuesAreDropped(): void
    {
        $defaults = ['basic' => [
            'site_logo_max_height' => ['value' => '40'],
            'footer_copyright_text' => ['value' => '© {year} {site_name} 版权所有.'],
            'site_name' => ['value' => 'Yikai CMS'],
        ]];
        $submitted = ['site_logo_max_height' => '40', 'footer_copyright_text' => '', 'site_name' => 'Yikai CMS', 'site_name_en' => 'x'];
        self::assertSame(['site_name' => 'Yikai CMS', 'site_name_en' => 'x'],
            DeclaredSettingRows::withoutUntouched($submitted, $defaults, ['site_name' => 'Flow']));
        self::assertSame(['site_logo_max_height' => '60', 'footer_copyright_text' => '© Flow'],
            DeclaredSettingRows::withoutUntouched(['site_logo_max_height' => '60', 'footer_copyright_text' => '© Flow'], $defaults, []));
    }

    public function testSettingsPagesRenderDeclaredRows(): void
    {
        foreach (['admin/setting.php', 'admin/setting_contact.php'] as $page) {
            $source = (string) file_get_contents(ROOT_PATH . '/' . $page);
            self::assertStringContainsString('DeclaredSettingRows::complete(', $source, $page);
            self::assertStringContainsString('DeclaredSettingRows::withoutUntouched(', $source, $page);
        }
    }
}
