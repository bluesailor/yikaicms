<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The "choose page" dropdown is right-aligned to its button and 18rem wide.
 * When the button is not at the panel's right edge (e.g. the header nav's CTA
 * link field), the list stuck out of the settings panel and its left side
 * was clipped. It now shrinks and shifts itself back inside once shown.
 */
final class BloxLinkPickerPlacementTest extends TestCase
{
    public function testDropdownPlacesItselfInsideThePanelOnceShown(): void
    {
        $picker = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/link-picker.php');
        self::assertStringContainsString('x-effect="if (linkPickerOpen(<?= e($linkPickerId) ?>)) $nextTick(() => placeLinkPicker($el))"', $picker);

        $methods = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/link-picker-methods.php');
        $place = substr($methods, (int) strpos($methods, 'placeLinkPicker(menu, attempt) {'), 1600);
        // Never measure while hidden: wait a few frames for x-show instead.
        self::assertStringContainsString('if (menu.offsetParent === null) {', $place);
        self::assertStringContainsString('(attempt || 0) < 10', $place);
        self::assertStringContainsString("closest('.overflow-y-auto, .overflow-auto, aside')", $place);
        self::assertStringContainsString("if (menu.offsetWidth > maxWidth) menu.style.width = maxWidth + 'px';", $place);
        self::assertStringContainsString("if (shift) menu.style.right = (-shift) + 'px';", $place);
    }
}
