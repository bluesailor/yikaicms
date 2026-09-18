<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxRuntimeBoundaryTest extends TestCase
{
    public function testRealRuntimeDoesNotLoadDetailAuthoringServices(): void
    {
        $lines = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(
            dirname(__DIR__) . '/fixtures/blox-runtime-boundary-probe.php'
        ), $lines, $exit);
        self::assertSame(0, $exit);
        $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([false, false, false], $result['before']);
        self::assertSame([false, false, false], $result['afterRender']);
        self::assertStringContainsString('Published &lt;content&gt;', $result['visible']);
        self::assertSame('', $result['hidden']);
        self::assertTrue($result['editingRejected']);
        self::assertSame([true, true, true], $result['afterEditor']);
        foreach (['product-detail', 'article-detail'] as $type) {
            self::assertTrue($result['detailSeeds'][$type]['editable']);
            self::assertTrue($result['detailSeeds'][$type]['has_sections']);
            self::assertTrue($result['detailSeeds'][$type]['stable']);
        }
    }
}
