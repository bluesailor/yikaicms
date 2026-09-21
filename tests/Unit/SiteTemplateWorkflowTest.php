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
        foreach (['site_url', 'smtp_pass', 'license_key', 'admin_lang', 'cron_token', 'upload_file_types', 'demo_mode', 'static_html_enabled'] as $key) {
            self::assertFalse(SiteTemplateData::settingAllowed($key), $key);
        }
        foreach (['site_name', 'site_logo', 'current_theme', 'theme_content_sample', 'home_blox_published', 'site_lang'] as $key) {
            self::assertTrue(SiteTemplateData::settingAllowed($key), $key);
        }
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
}
