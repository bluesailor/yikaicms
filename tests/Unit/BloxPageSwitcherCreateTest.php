<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Page Builder title names the page being edited (draft state lives in the
 * save status and the unpublished-changes badge), and the page switcher can
 * create a page in the language being edited without leaving the builder.
 */
final class BloxPageSwitcherCreateTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }

    public function testHomeTitleDoesNotClaimADraft(): void
    {
        $header = self::source('admin/blox_editor/partials/header.php');

        self::assertStringNotContainsString("__('blox_home_draft')", $header);
        self::assertSame(2, substr_count($header, "\$isHomeBlox ? __('blox_page_switch_home') : \$page['name']"));
    }

    public function testSwitcherCreatesPagesThroughThePageManager(): void
    {
        $header = self::source('admin/blox_editor/partials/header.php');
        self::assertStringContainsString('x-show="pageSwitcherCreate.can"', $header);
        self::assertStringContainsString('@submit.prevent="createPageFromSwitcher(name)"', $header);

        $editor = self::source('admin/blox_editor.php');
        self::assertStringContainsString("\$bloxPageSwitcherCreate = ['can' => hasPermission('edit_page'), 'lang' => \$switchLanguage];", $editor);

        $methods = self::source('admin/blox_editor/partials/advanced-code-methods.php');
        $create = substr($methods, (int) strpos($methods, 'createPageFromSwitcher(name) {'), 1600);
        // Confirm unsaved work before creating, so cancelling never leaves an orphan page behind.
        self::assertLessThan(
            strpos($create, 'fetch('),
            strpos($create, 'this.hasUnsavedChanges() && !window.confirm(this.uiText.leaveUnsavedConfirm)')
        );
        self::assertStringContainsString('body.set("view_lang", String(this.pageSwitcherCreate.lang || ""));', $create);
        self::assertStringContainsString('"/admin/blox_editor.php?id=" + id', $create);
    }

    public function testPageManagerCreatesInTheViewedLanguage(): void
    {
        $page = self::source('admin/page.php');
        $create = substr($page, (int) strpos($page, "if (\$action === 'create') {"), 900);

        // Without lang the row falls back to the column default (zh-CN) and disappears
        // from the English/Japanese page list it was just created in.
        self::assertStringContainsString("'lang' => adminLangView()['view'],", $create);
        self::assertStringContainsString("formData.append('view_lang', <?php echo json_encode(\$_viewLang); ?>);", $page);
    }
}
