<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DetailRuntimeEntitlementTest extends TestCase
{
    public function testPublishedDetailsIgnoreEditorEntitlementsAndPreserveNativeFallback(): void
    {
        $lines = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(
            dirname(__DIR__) . '/fixtures/detail-runtime-entitlement-probe.php'
        ), $lines, $exit);
        $this->assertSame(0, $exit);
        $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('<h1>Published product</h1>', $result['product']);
        $this->assertStringContainsString('<h1>Published article</h1>', $result['article']);
        $this->assertNull($result['product_context']);
        $this->assertNull($result['article_context']);
        $this->assertSame('', $result['native_product']);
        $this->assertSame('', $result['native_article']);
    }
}
