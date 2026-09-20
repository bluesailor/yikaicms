<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxTrustedWriteTest extends TestCase
{
    /** 发布策略（全部 licensed）下：官方预置照常可用，作者保存同样的内容仍需授权。 */
    public function testBundledPresetsBypassAuthoringChecksButAuthorSavesDoNot(): void
    {
        $lines = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/blox-trusted-write-probe.php') . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, implode("\n", $lines));
        $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing', 'interactions'], $result['denied']); // v1.28 增 interactions
        self::assertCount(6, $result['footer_catalog']);
        self::assertContains('four-column-dark-site-footer', $result['footer_catalog']);
        self::assertSame('license', $result['author_save']);
        self::assertSame('saved', $result['trusted_save']);
        self::assertFalse($result['trusted_after_throw']);
    }
}
