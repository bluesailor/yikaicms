<?php
/** Admin sidebar accessibility interaction contract. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminSidebarAccessibilityTest extends TestCase
{
    public function testMobileDrawerManagesVisibilityScrollAndFocus(): void
    {
        $header = $this->source('admin/includes/header.php');

        self::assertStringContainsString('syncMobileSidebarA11y()', $header);
        self::assertStringContainsString('this.$refs.sidebar.inert = hidden;', $header);
        self::assertStringContainsString("setAttribute('aria-hidden', hidden ? 'true' : 'false')", $header);
        self::assertStringContainsString("document.body.style.overflow = 'hidden';", $header);
        self::assertStringContainsString('trapMobileFocus(event)', $header);
        self::assertStringContainsString('@keydown.escape.window="mobileMenu && closeMobileMenu()"', $header);
        self::assertStringContainsString('this._mobileLastFocus.focus()', $header);
    }

    public function testGroupsUseSemanticDisclosureButtons(): void
    {
        $header = $this->source('admin/includes/header.php');

        self::assertStringContainsString('<button type="button" @click="collapsed ? flyOpenAndFocus', $header);
        self::assertStringContainsString(':aria-expanded="(collapsed ? fly.key ===', $header);
        self::assertStringContainsString(":aria-controls=\"collapsed ? 'adminSidebarFlyout'", $header);
        self::assertStringNotContainsString('<div @click="collapsed ? null : toggle(', $header);
    }

    public function testCollapsedFlyoutHasKeyboardMenuPath(): void
    {
        $header = $this->source('admin/includes/header.php');

        self::assertStringContainsString('role="menu"', $header);
        self::assertStringContainsString('role="menuitem"', $header);
        self::assertStringContainsString('@keydown.escape.prevent.stop="flyClose(true)"', $header);
        self::assertStringContainsString('@keydown.arrow-down.prevent="flyMoveFocus($event, 1)"', $header);
        self::assertStringContainsString("@keydown.home.prevent=\"flyMoveFocus(\$event, 'first')\"", $header);
        self::assertStringContainsString("this.\$refs.flyPanel.querySelector('a[href]')", $header);
        self::assertStringContainsString(":aria-current=\"it.active ? 'page' : null\"", $header);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }
}
