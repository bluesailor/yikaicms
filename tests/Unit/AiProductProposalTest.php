<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * T05：在独立子进程里跑产品内容提案的行为验证（s103-* 一次性 SQLite 文件库，不能用 bootstrap 的内存库）。
 * MySQL 版本由 tests/ai-access/run-local-mysql8.ps1 在一次性实例上跑同一个脚本。
 */
final class AiProductProposalTest extends TestCase
{
    public function testProductProposalsOnSqlite(): void
    {
        $path = sys_get_temp_dir() . '/s103-product-proposals-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/ai-access/product-proposal-run.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH,
                ['YK_S103_DRIVER' => 'sqlite', 'YK_S103_SQLITE_PATH' => $path] + getenv());
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $report = json_decode($stdout, true);
            self::assertIsArray($report, 'runner did not produce a JSON report: ' . substr($stderr . $stdout, 0, 500));
            $failures = [];
            foreach ($report['scenarios'] as $scenario => $lines) {
                foreach ($lines as $line) if (str_starts_with($line, 'FAIL')) $failures[] = $scenario . ': ' . $line;
            }
            self::assertSame([], $failures);
            self::assertGreaterThanOrEqual(58, $report['assertions']);
            self::assertSame(0, $exit);
        } finally {
            foreach ([$path, $path . '-journal'] as $file) if (is_file($file)) unlink($file);
        }
    }
}
