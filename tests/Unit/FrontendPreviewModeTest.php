<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/frontend_preview.php';

final class FrontendPreviewModeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalGet;

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
        unset($_GET['preview']);
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
    }

    public function testNormalFrontendRequestIsNotCleanPreview(): void
    {
        self::assertFalse(isCleanFrontendPreview());
    }

    public function testPreviewParameterPresenceEnablesCleanPreview(): void
    {
        $_GET['preview'] = '';
        self::assertTrue(isCleanFrontendPreview());

        $_GET['preview'] = '0';
        self::assertTrue(isCleanFrontendPreview());
    }

    public function testFrontendIntegrationsUseTheSharedPreviewGate(): void
    {
        foreach (['includes/admin_bar.php', 'includes/front_edit.php', 'index.php', 'page.php'] as $path) {
            $source = file_get_contents(ROOT_PATH . '/' . $path);
            self::assertIsString($source);
            self::assertStringContainsString('isCleanFrontendPreview()', $source, $path);
        }
    }

    public function testFrontendAdminBarUpgradesLayoutEditLinksToBlox(): void
    {
        $adminBar = file_get_contents(ROOT_PATH . '/includes/admin_bar.php');
        self::assertIsString($adminBar);
        self::assertStringContainsString('function adminBarResolveEditUrl', $adminBar);
        self::assertStringNotContainsString('bloxPageEditorEnabled()', $adminBar);
        self::assertStringContainsString('bloxAdvancedFeaturesEnabled()', $adminBar);
        self::assertStringContainsString("return '/admin/blox_editor.php?' . \$query;", $adminBar);
        self::assertStringContainsString("'/admin/setting_home.php'", $adminBar);
        self::assertStringContainsString('adminBarResolveEditUrl((string) ($GLOBALS[\'ik_edit_url\'] ?? \'\'))', $adminBar);

        $home = file_get_contents(ROOT_PATH . '/index.php');
        $contact = file_get_contents(ROOT_PATH . '/contact.php');
        self::assertIsString($home);
        self::assertIsString($contact);
        self::assertStringContainsString("'/admin/blox_editor.php?home=1'", $home);
        self::assertStringContainsString("\$GLOBALS['ik_edit_url'] = '/admin/blox_editor.php?id='", $contact);
    }
}
