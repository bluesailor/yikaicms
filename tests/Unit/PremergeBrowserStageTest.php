<?php
/** 2.1.1：门禁可选的浏览器回归阶段——参数接得住，列出的 spec 都在、都有 @ci 用例（改名时这里先红）。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PremergeBrowserStageTest extends TestCase
{
    public function testBrowserStageIsOptInAndItsSpecsExist(): void
    {
        $script = (string) file_get_contents(ROOT_PATH . '/tools/premerge-check.sh');
        self::assertStringContainsString('--browser) BROWSER="1"', $script);
        self::assertStringContainsString('BROWSER="${PREMERGE_BROWSER:-0}"', $script, '默认不跑，要显式打开');
        self::assertStringContainsString("node tests/e2e/run-local.js --grep '@ci' --project desktop-1440", $script);

        self::assertSame(1, preg_match('/^BROWSER_SPECS=\(([^)]*)\)$/m', $script, $m));
        $specs = preg_split('/\s+/', trim($m[1])) ?: [];
        self::assertContains('blox-editor', $specs);
        foreach ($specs as $spec) {
            $file = ROOT_PATH . '/tests/e2e/' . $spec . '.spec.js';
            self::assertFileExists($file, $spec);
            self::assertStringContainsString('@ci', (string) file_get_contents($file), $spec . ' 没有 @ci 用例，阶段会空跑');
        }
    }
}
