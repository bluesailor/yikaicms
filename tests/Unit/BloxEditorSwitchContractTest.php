<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxEditorSwitchContractTest extends TestCase
{
    public function testSwitchUsesAjaxInsteadOfNavigatingToJsonResponse(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/admin/license.php');

        self::assertIsString($source);
        self::assertStringContainsString("document.getElementById('bloxSwitchForm').addEventListener('submit'", $source);
        self::assertStringContainsString('e.preventDefault();', $source);
        self::assertStringContainsString('adminSave(this, {', $source);
        self::assertStringContainsString("this.querySelector('button[type=\"submit\"]')", $source);
        self::assertStringContainsString("config('blox_editor_enabled', '1')", $source);
        self::assertStringContainsString('bloxPageEditorEnabled() && !bloxAdvancedFeaturesEnabled()', $source);
    }
}
