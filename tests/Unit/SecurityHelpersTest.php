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
}
