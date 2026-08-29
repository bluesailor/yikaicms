<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxEditorSwitchContractTest extends TestCase
{
    public function testLicensePageDoesNotExposeTheFreeBloxEditorAsALicensedSwitch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/admin/license.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('blox_toggle', $source);
        self::assertStringNotContainsString('bloxSwitchForm', $source);
        self::assertStringNotContainsString('blox_switch_title', $source);
        self::assertStringNotContainsString("config('blox_editor_enabled', '1')", $source);
    }
}
