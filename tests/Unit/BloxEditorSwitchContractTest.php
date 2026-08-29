<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxEditorSwitchContractTest extends TestCase
{
    public function testFreeBloxEditorHasNoOrphanRuntimeSwitch(): void
    {
        $root = dirname(__DIR__, 2);
        $license = file_get_contents($root . '/admin/license.php');
        $functions = file_get_contents($root . '/includes/functions.php');
        $defaults = require $root . '/config/defaults.php';

        self::assertIsString($license);
        self::assertIsString($functions);
        self::assertStringNotContainsString('blox_toggle', $license);
        self::assertStringNotContainsString('bloxSwitchForm', $license);
        self::assertStringNotContainsString('blox_switch_title', $license);
        self::assertStringNotContainsString("config('blox_editor_enabled', '1')", $functions);
        self::assertArrayNotHasKey('blox_editor_enabled', $defaults['system']);
    }
}
