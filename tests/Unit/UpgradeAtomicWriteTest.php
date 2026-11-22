<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpgradeRunner.php';

/**
 * 在线升级写程序文件：先写同目录临时文件、核对长度、再改名替换（深度评审「原地替换」的短期建议）。
 * 别的请求读不到写了一半的文件；写不进去时原文件保持原样、不留临时文件。
 */
final class UpgradeAtomicWriteTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yk_atomic_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $name) {
            $path = $this->dir . '/' . $name;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return array_values(array_filter(scandir($this->dir) ?: [], static fn (string $name): bool => str_contains($name, 'yk-upgrade')));
    }

    public function testReplacesAnExistingFileAndLeavesNoTemporaryFile(): void
    {
        $file = $this->dir . '/functions.php';
        file_put_contents($file, '<?php // old');
        self::assertTrue(uo_write_file_atomic($file, '<?php // new and longer'));
        self::assertSame('<?php // new and longer', file_get_contents($file));
        self::assertSame([], $this->leftovers());
    }

    public function testCreatesANewFile(): void
    {
        $file = $this->dir . '/NewClass.php';
        self::assertTrue(uo_write_file_atomic($file, '<?php final class NewClass {}'));
        self::assertSame('<?php final class NewClass {}', file_get_contents($file));
        self::assertSame([], $this->leftovers());
    }

    public function testFailureLeavesTheOriginalUntouched(): void
    {
        // 目标是一个目录：改名与直接写入都会失败
        $target = $this->dir . '/assets';
        mkdir($target);
        self::assertFalse(uo_write_file_atomic($target, 'data'));
        self::assertDirectoryExists($target);
        self::assertSame([], $this->leftovers(), '失败时不留临时文件');

        // 目录不存在：连临时文件都写不了
        self::assertFalse(uo_write_file_atomic($this->dir . '/missing/a.php', 'data'));
    }

    /** 只读的目标照原地写入时的结果报失败：Linux 上改名能覆盖只读文件，不能借此绕过主机的权限设置（2.3.0 CI 查出） */
    public function testReadOnlyTargetIsReportedAsAFailureAndLeftUntouched(): void
    {
        $file = $this->dir . '/locked.php';
        file_put_contents($file, '<?php // locked by the host');
        chmod($file, 0444);
        clearstatcache(true, $file);
        try {
            if (is_writable($file)) self::markTestSkipped('chmod cannot make a file read-only on this platform/user');
            self::assertFalse(uo_write_file_atomic($file, '<?php // new'));
            self::assertSame('<?php // locked by the host', file_get_contents($file));
            self::assertSame([], $this->leftovers());
        } finally {
            chmod($file, 0644);
        }
    }

    public function testTheReadOnlyRuleIsInTheWriter(): void
    {
        // Windows 上 chmod 常常改不成只读，上一项会跳过；这里在源码层面守住同一条规则
        $source = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertStringContainsString('if (is_file($dest) && !is_writable($dest)) return false;', $source);
    }

    public function testCopyRestoresASnapshotByteForByte(): void
    {
        $snapshot = $this->dir . '/snapshot.bin';
        $bytes = random_bytes(4096) . "\r\n\0";
        file_put_contents($snapshot, $bytes);
        $dest = $this->dir . '/restored.bin';
        file_put_contents($dest, 'newer version');
        self::assertTrue(uo_copy_file_atomic($snapshot, $dest));
        self::assertSame($bytes, file_get_contents($dest));
        self::assertFalse(uo_copy_file_atomic($this->dir . '/absent', $dest));
        self::assertSame($bytes, file_get_contents($dest), '快照读不到时不动目标');
    }

    public function testTemporaryFileIsNotServedAsStaticText(): void
    {
        // config.php 的临时文件里有数据库口令：名字必须以点开头、以 .php 结尾，被杀进程留下它时也不会被当静态文件发出去
        $source = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertStringContainsString("'/.yk-upgrade-' . basename(\$dest) . '.tmp.php'", $source);
    }

    public function testEveryProgramFileWriteInTheUpgraderIsAtomic(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertStringNotContainsString('@file_put_contents($d, $data)', $source, '分批覆盖程序文件');
        self::assertStringNotContainsString('@copy($s, $d)', $source, '回滚时放回快照');
        self::assertStringNotContainsString("@copy(\$configBackup, ROOT_PATH . '/config/config.php')", $source, '恢复 config.php');
        self::assertStringNotContainsString('@file_put_contents($cf, $new)', $source, '修补 config.php');
    }
}
