<?php
/**
 * Tests for the v1.9.3 security helpers in includes/functions.php:
 *   - zipUnsafeEntry() — zip-slip 条目检测
 *   - sanitizeSvg()    — SVG XSS 净化
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once ROOT_PATH . '/includes/security.php';

final class SecurityHelpersTest extends TestCase
{
    public function testFakeWebmWithGenericBinaryMimeIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yk_webm_fake_');
        self::assertIsString($path);
        file_put_contents($path, '<?php echo "not video";');
        try {
            self::assertFalse(uploadMimeMatches('webm', 'application/octet-stream', $path, ['video/webm']));
        } finally {
            @unlink($path);
        }
    }

    public function testGenericMimeWebmRequiresEbmlSignature(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yk_webm_valid_');
        self::assertIsString($path);
        file_put_contents($path, "\x1A\x45\xDF\xA3" . random_bytes(16));
        try {
            self::assertTrue(uploadMimeMatches('webm', 'application/octet-stream', $path, ['video/webm']));
            self::assertTrue(uploadMimeMatches('webm', 'video/webm', $path, ['video/webm']));
        } finally {
            @unlink($path);
        }
    }

    // ---- zipUnsafeEntry ----

    private function makeZip(array $entries): string
    {
        $path = sys_get_temp_dir() . '/yk_ziptest_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name) {
            $zip->addFromString($name, 'x');
        }
        $zip->close();
        return $path;
    }

    public function testZipCleanEntriesPass(): void
    {
        $path = $this->makeZip(['plugin/plugin.json', 'plugin/src/a.php', 'plugin/assets/x.css']);
        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertNull(zipUnsafeEntry($zip));
        $zip->close();
        unlink($path);
    }

    public function testZipParentTraversalRejected(): void
    {
        $path = $this->makeZip(['plugin/plugin.json', '../evil.php']);
        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertSame('../evil.php', zipUnsafeEntry($zip));
        $zip->close();
        unlink($path);
    }

    public function testZipNestedTraversalRejected(): void
    {
        $path = $this->makeZip(['plugin/../../etc/x']);
        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertNotNull(zipUnsafeEntry($zip));
        $zip->close();
        unlink($path);
    }

    public function testZipAbsolutePathRejected(): void
    {
        // ZipArchive::addFromString 会规范化前导斜杠，这里直接测函数逻辑：
        // 用一个假的 zip-like 对象无法造，改测 backslash 盘符形态经 addFromString 保留的情况。
        $path = $this->makeZip(['plugin/plugin.json', 'C:\\windows\\x']);
        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertNotNull(zipUnsafeEntry($zip));
        $zip->close();
        unlink($path);
    }

    // ---- sanitizeSvg ----

    public function testSvgStripsScript(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect/></svg>';
        $out = sanitizeSvg($svg);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<rect', $out);
    }

    public function testSvgStripsEventHandlers(): void
    {
        $svg = '<svg onload="alert(1)"><rect onclick="steal()"/></svg>';
        $out = sanitizeSvg($svg);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    public function testSvgNeutralizesJavascriptHref(): void
    {
        $svg = '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>';
        $out = sanitizeSvg($svg);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testSvgStripsForeignObjectAndDoctype(): void
    {
        $svg = '<!DOCTYPE svg><svg><foreignObject><body>hi</body></foreignObject></svg>';
        $out = sanitizeSvg($svg);
        $this->assertStringNotContainsString('<!DOCTYPE', $out);
        $this->assertStringNotContainsString('foreignObject', $out);
    }

    public function testSvgKeepsPlainMarkup(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        $this->assertStringContainsString('<circle', sanitizeSvg($svg));
    }

    // ---- zipResourceViolation（zip bomb 资源限制）----

    /** @param array<string,string> $entries name => content */
    private function makeContentZip(array $entries): ZipArchive
    {
        $path = sys_get_temp_dir() . '/yk_zipres_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $zip = new ZipArchive();
        $zip->open($path);
        return $zip;
    }

    public function testNormalZipPassesResourceCheck(): void
    {
        $zip = $this->makeContentZip(['a.php' => 'hello', 'b/c.css' => str_repeat('x', 2048)]);
        $this->assertNull(zipResourceViolation($zip));
        $zip->close();
    }

    public function testTooManyEntriesIsRejected(): void
    {
        $zip = $this->makeContentZip(['a' => '1', 'b' => '2', 'c' => '3']);
        $this->assertStringContainsString('too many entries', (string) zipResourceViolation($zip, 2));
        $zip->close();
    }

    public function testOversizeSingleFileIsRejected(): void
    {
        $zip = $this->makeContentZip(['big.bin' => random_bytes(4096)]);
        $violation = zipResourceViolation($zip, 5000, 209_715_200, 1024);
        $this->assertStringContainsString('entry too large', (string) $violation);
        $zip->close();
    }

    public function testTotalSizeCapIsRejected(): void
    {
        $zip = $this->makeContentZip(['a.bin' => random_bytes(3000), 'b.bin' => random_bytes(3000)]);
        $violation = zipResourceViolation($zip, 5000, 4096, 209_715_200);
        $this->assertStringContainsString('total uncompressed size', (string) $violation);
        $zip->close();
    }

    public function testHighCompressionRatioBombIsRejected(): void
    {
        // 2MB 全零：deflate 后千比一级别，size>1MB 起判线也过——典型 zip bomb 形态
        $zip = $this->makeContentZip(['bomb.dat' => str_repeat("\0", 2_097_152)]);
        $this->assertStringContainsString('compression ratio', (string) zipResourceViolation($zip));
        $zip->close();
    }

    public function testSmallHighRatioFileIsNotFalselyFlagged(): void
    {
        // 小文本天然高压缩比，1MB 以下不该误伤
        $zip = $this->makeContentZip(['tiny.txt' => str_repeat('a', 500_000)]);
        $this->assertNull(zipResourceViolation($zip));
        $zip->close();
    }
}
