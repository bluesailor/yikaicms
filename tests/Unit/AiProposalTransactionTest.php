<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * S1-03：在独立子进程里运行提案事务原型的行为验证（多进程并发需要文件库/真实服务器，
 * 不能用 bootstrap 的内存 SQLite）。MySQL/MariaDB 只在显式提供 s103_* 测试库时运行。
 */
final class AiProposalTransactionTest extends TestCase
{
    public function testPrototypeOnSqliteFile(): void
    {
        $path = sys_get_temp_dir() . '/s103-phpunit-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $this->assertRunnerPasses(['YK_S103_DRIVER' => 'sqlite', 'YK_S103_SQLITE_PATH' => $path]);
        } finally {
            foreach ([$path, $path . '-journal'] as $file) if (is_file($file)) unlink($file);
        }
    }

    public function testAssistantApplyUndoOnSqliteFile(): void
    {
        $path = sys_get_temp_dir() . '/s103-phpunit-assistant-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/ai-access/assistant-txn-run.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH,
                ['YK_S103_DRIVER' => 'sqlite', 'YK_S103_SQLITE_PATH' => $path] + getenv());
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertStringContainsString('failed=0', $stdout);
        } finally {
            foreach ([$path, $path . '-journal'] as $file) if (is_file($file)) unlink($file);
        }
    }

    public function testUpgradeAndBackupRestoreOnSqliteFile(): void
    {
        $path = sys_get_temp_dir() . '/s103-upgrade-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/ai-access/upgrade-run.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH,
                ['YK_S103_DRIVER' => 'sqlite', 'YK_S103_SQLITE_PATH' => $path] + getenv());
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertStringContainsString('assertions=7 failed=0', $stdout);
        } finally {
            foreach ([$path, $path . '-journal'] as $file) if (is_file($file)) unlink($file);
        }
    }

    public function testPrototypeOnMysqlWhenConfigured(): void
    {
        $name = (string) getenv('YK_S103_MYSQL_NAME');
        if ($name === '' || !extension_loaded('pdo_mysql')) {
            self::markTestSkipped('Set YK_S103_MYSQL_NAME=s103_* (and _HOST/_PORT/_USER/_PASSWORD) to run against a disposable MySQL/MariaDB database.');
        }
        $env = ['YK_S103_DRIVER' => 'mysql'];
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD'] as $key) $env['YK_S103_MYSQL_' . $key] = (string) getenv('YK_S103_MYSQL_' . $key);
        $this->assertRunnerPasses($env);
    }

    /** @param array<string,string> $env */
    private function assertRunnerPasses(array $env): void
    {
        $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/ai-access/proposal-txn-run.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH, $env + getenv());
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report = json_decode($stdout, true);
        self::assertIsArray($report, 'runner did not produce a JSON report: ' . substr($stderr, 0, 500));
        $failures = [];
        foreach ($report['scenarios'] as $scenario => $checks) {
            foreach ($checks as $line) if (str_starts_with($line, 'FAIL')) $failures[] = $scenario . ': ' . $line;
        }
        self::assertSame([], $failures);
        self::assertGreaterThanOrEqual(100, $report['assertions']);
        self::assertSame(0, $exit);
    }
}
