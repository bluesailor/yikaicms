<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once ROOT_PATH . '/includes/SiteTemplateArchive.php';
require_once ROOT_PATH . '/includes/ThemeContent.php';

final class SiteTemplateWorkflowTest extends TestCase
{
    public function testContentOnlyRoundtripAndPersistentRestore(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-probe.php'));
        self::assertSame("Site template roundtrip passed\n", $output);
    }

    public function testRuntimeIdentityCannotBeImported(): void
    {
        foreach (['site_url', 'smtp_pass', 'license_key', 'admin_lang', 'cron_token', 'upload_file_types', 'demo_mode', 'static_html_enabled',
            'shop_payment_secret', 'shop_api_key', 'seo_api_key', 'cookie_consent_secret'] as $key) {
            self::assertFalse(SiteTemplateData::settingAllowed($key), $key);
        }
        foreach (['site_name', 'site_logo', 'current_theme', 'theme_content_sample', 'home_blox_published', 'site_lang'] as $key) {
            self::assertTrue(SiteTemplateData::settingAllowed($key), $key);
        }
    }

    public function testPluginDependencyReviewIsVisibleInTheAdmin(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_templates.php');
        self::assertStringContainsString("\$preview['missing_plugins']", $page);
        self::assertStringContainsString('/admin/plugin.php?tab=market&amp;q=', $page);
        self::assertStringContainsString("post('trusted') === '1', post('confirm') === '1')", $page);
        self::assertStringContainsString('name="confirm" value="1" required', $page);
    }

    public function testRewritingPreservesExternalReferencesAndJson(): void
    {
        $value = '{"src":"/uploads/a.png","external":"https://other.test/uploads/a.png"}';
        $mapped = json_decode(SiteTemplateArchive::rewrite($value, ['/uploads/' => '/uploads/new/']), true);
        self::assertSame('/uploads/new/a.png', $mapped['src']);
        self::assertSame('https://other.test/uploads/a.png', $mapped['external']);
    }

    public function testThemeFieldsUseSharedUrlPolicy(): void
    {
        self::assertSame('/contact.html', ThemeContent::normalize(['type' => 'url'], '/contact.html'));
        self::assertSame('0', ThemeContent::normalize(['type' => 'toggle'], 'false'));
        self::assertSame('Hello', ThemeContent::localized(['en' => 'Hello', 'zh-CN' => 'Fallback'], 'en'));
        $this->expectExceptionMessage('tc_url');
        ThemeContent::normalize(['type' => 'image'], 'javascript:alert(1)');
    }
    /** 导入向导：进行中只摆导入步骤；官方包免「信任」勾选但「确认」必填；导入完成给下一步并把检查归组 */
    public function testImportWizardStaysFocusedAndFinishesWithNextSteps(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_templates.php');
        $wizard = strpos($page, 'data-testid="st-import-wizard"');
        $export = strpos($page, 'aria-labelledby="st-export"');
        self::assertIsInt($wizard);
        self::assertIsInt($export);
        self::assertStringContainsString('<?php elseif ($notice !== \'st_applied\'): ?>', substr($page, $wizard, $export - $wizard), '导入进行中或刚完成时不显示上传与导出');
        self::assertStringContainsString('$importing = is_array($preview) && $notice !== \'st_applied\';', $page);
        $trust = strpos($page, 'name="trusted" value="1" required');
        self::assertIsInt($trust);
        self::assertLessThan($trust, (int) strpos($page, '<?php if ($official): ?>'), '信任勾选只在非官方包的分支里');
        self::assertStringContainsString('name="confirm" value="1" required', substr($page, $wizard));
        self::assertStringContainsString('data-testid="st-done-<?= e($doneKey) ?>"', $page);
        self::assertStringContainsString("['home', 'ti-layout-dashboard', SiteSetup::homeEditUrl(), false]", $page);
        self::assertStringContainsString('$groupReport($report[\'items\'])', $page);
        // 预填用模板自带的资料，提交失败时保留管理员填的
        self::assertStringContainsString('if (is_array($preview) && $_SERVER[\'REQUEST_METHOD\'] !== \'POST\') {', $page);
    }
}
