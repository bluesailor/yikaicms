<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * S1-04：在独立子进程里运行 MCP 协议层的 HTTP 行为验证（自起 php -S 与 s103-* 文件库，
 * 不能用 bootstrap 的内存 SQLite）。不调用模型，不连接真实客户端。
 */
final class AiMcpProtocolTest extends TestCase
{
    public function testProtocolPrototypeOverHttp(): void
    {
        $stem = sys_get_temp_dir() . '/s103-mcp-phpunit-' . bin2hex(random_bytes(6));
        $env = ['YK_S103_DRIVER' => 'sqlite', 'YK_S103_SQLITE_PATH' => $stem . '.sqlite'];
        try {
            $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/ai-access/mcp-protocol-run.php', '--out=' . $stem . '.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH, $env + getenv());
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $report = is_file($stem . '.json') ? json_decode((string) file_get_contents($stem . '.json'), true) : null;
            self::assertIsArray($report, 'runner did not write a report: ' . substr($stderr . $stdout, 0, 500));
            $failures = [];
            foreach ($report['scenarios'] as $scenario => $checks) {
                foreach ($checks as $check) if ($check['ok'] !== true) $failures[] = $scenario . ': ' . $check['label'];
            }
            self::assertSame([], $failures);
            self::assertGreaterThanOrEqual(70, $report['assertions']);
            self::assertSame(0, $exit);
        } finally {
            foreach ([$stem . '.sqlite', $stem . '.sqlite-journal', $stem . '.json'] as $file) if (is_file($file)) unlink($file);
        }
    }
}
