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
        // 2026-08-28 起 Blox 全部能力对免费版开放，bloxAdvancedFeaturesEnabled() 等价于
        // bloxPageEditorEnabled()，原「开关已开但需授权」的黄色提示条件恒为 false，已删除。
        // 反向断言防它连同授权判定一起回潮。
        self::assertStringNotContainsString('bloxPageEditorEnabled() && !bloxAdvancedFeaturesEnabled()', $source);
        self::assertStringNotContainsString('blox_switch_needs_license', $source);
    }
}
