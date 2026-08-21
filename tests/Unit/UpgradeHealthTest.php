<?php
/**
 * UpgradeHealth 升级后健康自检（v1.18.5 事务化升级的一环）：
 * 语法坏文件 / 缺文件 / version.php 缺失都要判不健康——它们是
 * 「升级中断形成新旧代码混合状态」的典型表现，触发一键回滚提示。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UpgradeHealth;

require_once ROOT_PATH . '/includes/UpgradeHealth.php';

final class UpgradeHealthTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/yk_uh_' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmpRoot);
    }

    private function writeVersion(string $version = '1.18.5'): void
    {
        file_put_contents(
            $this->tmpRoot . '/config/version.php',
            "<?php\ndefine('CMS_VERSION', '{$version}');\n"
        );
    }

    public function testHealthyTreePasses(): void
    {
        file_put_contents($this->tmpRoot . '/index.php', "<?php echo 'ok';\n");
        $this->writeVersion();

        $r = UpgradeHealth::check($this->tmpRoot, ['index.php', 'config/version.php']);

        $this->assertTrue($r['ok']);
        $this->assertSame('1.18.5', $r['version']);
    }

    public function testSyntaxBrokenFileFailsCheck(): void
    {
        // 模拟覆盖写到一半被截断的核心文件
        file_put_contents($this->tmpRoot . '/index.php', "<?php function broken( {\n");
        $this->writeVersion();

        $r = UpgradeHealth::check($this->tmpRoot, ['index.php', 'config/version.php']);

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['checks'][0]['ok']);
        $this->assertTrue($r['checks'][1]['ok']);
    }

    public function testMissingCoreFileFailsCheck(): void
    {
        $this->writeVersion();

        $r = UpgradeHealth::check($this->tmpRoot, ['index.php', 'config/version.php']);

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['checks'][0]['ok']);
    }

    public function testMissingVersionFileFailsEvenIfSyntaxOk(): void
    {
        file_put_contents($this->tmpRoot . '/index.php', "<?php echo 'ok';\n");

        $r = UpgradeHealth::check($this->tmpRoot, ['index.php']);

        $this->assertFalse($r['ok']);
        $this->assertSame('', $r['version']);
    }
}
