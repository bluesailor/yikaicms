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
        self::assertStringContainsString('BloxAreaEditorTarget::normalizeReturnTo', $adminBar);
        self::assertStringContainsString('BloxAreaEditorTarget::withReturnTo', $adminBar);

        $home = file_get_contents(ROOT_PATH . '/index.php');
        $contact = file_get_contents(ROOT_PATH . '/contact.php');
        self::assertIsString($home);
        self::assertIsString($contact);
        self::assertStringContainsString("'/admin/blox_editor.php?home=1'", $home);
        self::assertStringContainsString("\$GLOBALS['ik_edit_url'] = '/admin/blox_editor.php?id='", $contact);
    }

    public function testFrontendAdminBarProvidesAccessibleStableRegionNavigation(): void
    {
        $adminBar = file_get_contents(ROOT_PATH . '/includes/admin_bar.php');
        $frontEdit = file_get_contents(ROOT_PATH . '/includes/front_edit.php');
        self::assertIsString($adminBar);
        self::assertIsString($frontEdit);

        self::assertStringContainsString('<details id="ik-ab-regions"', $adminBar);
        self::assertStringContainsString('<summary aria-label="<?php echo e(__(\'ab_edit_regions\')); ?>">', $adminBar);
        self::assertStringContainsString('data-page-edit-url="<?php echo e($editUrl); ?>"', $adminBar);
        self::assertStringContainsString('data-testid="admin-edit-region-menu"', $adminBar);

        self::assertStringContainsString('function buildRegionNavigator()', $frontEdit);
        self::assertStringContainsString("target.searchParams.set('focus_section', sectionId)", $frontEdit);
        self::assertStringContainsString("elementTarget.searchParams.set('focus_element', elementId)", $frontEdit);
        self::assertStringContainsString('function withFrontendReturn(url)', $frontEdit);
        self::assertStringContainsString("source.searchParams.set('yk_focus_element', elementId)", $frontEdit);
        self::assertStringContainsString("source.searchParams.set('yk_focus_section', sectionId)", $frontEdit);
        self::assertStringContainsString("target.searchParams.set('return_to', source.pathname + source.search + source.hash)", $frontEdit);
        self::assertStringContainsString('function consumeReturnFocus()', $frontEdit);
        self::assertStringContainsString("window.history.replaceState(", $frontEdit);
        self::assertStringContainsString("target.scrollIntoView({", $frontEdit);
        self::assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)').matches", $frontEdit);
        self::assertStringContainsString('data-testid', $frontEdit);
        self::assertStringContainsString('frontend-return-focus-status', $frontEdit);
        self::assertStringContainsString("var groupOrder = ['page', 'header', 'body', 'footer'];", $frontEdit);
        self::assertStringContainsString("section.getAttribute('data-yk-sec-label') || fallbackLabel", $frontEdit);
        self::assertStringContainsString('link.title = item.label;', $frontEdit);
        self::assertStringContainsString("target.origin !== window.location.origin || !target.pathname.startsWith('/admin/')", $frontEdit);
        self::assertStringContainsString("section.setAttribute('aria-labelledby', heading.id);", $frontEdit);
        self::assertStringContainsString("if (event.key !== 'Escape' || !regions.open) return;", $frontEdit);
        self::assertStringContainsString('summary.focus();', $frontEdit);
    }
}
