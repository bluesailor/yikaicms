<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/tools/build_blox_runtime.php';

final class BloxEditorRuntimeBuildTest extends TestCase
{
    public function testGeneratedAssetMatchesStaticMethodSources(): void
    {
        $asset = bloxEditorRuntimeAsset();
        self::assertSame($asset, file_get_contents(ROOT_PATH . '/assets/js/yikay-editor-runtime.js'), 'Rebuild with php tools/build_blox_runtime.php');
        self::assertStringNotContainsString('<?', $asset);
        self::assertStringNotContainsString('csrfToken(', $asset);
        self::assertStringContainsString('Object.getOwnPropertyDescriptors', $asset);
        $entry = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringNotContainsString('            initTreeSortable() {', $entry);
        self::assertStringContainsString('window.BloxEditorRuntime.create({', $entry);
        self::assertLessThan(strpos($entry, '/assets/alpinejs/alpine.min.js'), strpos($entry, '/assets/js/yikay-editor-runtime.js'));
    }
}
