<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpgradeRunner.php';

/**
 * 分块续传的传输语义。
 *
 * 由来：升级的覆盖阶段早就分批了（每批 150 文件），下载却一直是「一个请求拉完整包」。
 * 国内主机拉官方服务器慢，Tengine/nginx 网关 60 秒一到就 504——xcidcn 两次栽在这里，
 * 每次都要人工 FTP 送包再手动接续。PHP 侧的 600 秒超时救不了，因为掐连接的是网关。
 *
 * 这里测的是传输本身，不涉及 RSA 验签与落位（那两步在 upgrade_download_chunk 里）。
 * 最容易写错、且错了要到最后 SHA256 才暴露的两条，各有一个用例锁住：
 *   · 换了目标包必须作废旧的半截文件；
 *   · 服务端忽略 Range 时必须清零重来，不能把「前半截 + 整包」拼在一起。
 */
final class UpgradeDownloadResumeTest extends TestCase
{
    private const URL = 'https://update.yikaicms.com/packages/yikaicms-v9.9.9.zip';

    private string $part;

    protected function setUp(): void
    {
        $this->part = sys_get_temp_dir() . '/yk-dl-part-' . bin2hex(random_bytes(6)) . '.zip.part';
    }

    protected function tearDown(): void
    {
        if (is_file($this->part)) {
            unlink($this->part);
        }
    }

    /** 造一个「按区间吐字节」的假服务端。 */
    private function server(string $payload, int $maxPerCall = PHP_INT_MAX): callable
    {
        return static function (string $url, int $from, int $to, $fh) use ($payload, $maxPerCall): array {
            $to = min($to, $from + $maxPerCall - 1, strlen($payload) - 1);
            if ($from > $to) {
                return [416, null, ''];
            }
            fwrite($fh, substr($payload, $from, $to - $from + 1));
            return [206, strlen($payload), ''];
        };
    }

    private function state(string $hash, string $ver = '9.9.9', ?int $total = null): array
    {
        return ['url' => self::URL, 'hash' => $hash, 'version' => $ver, 'total' => $total];
    }

    public function testResumesAcrossCallsUntilComplete(): void
    {
        $payload = random_bytes(300_000);
        $hash = hash('sha256', $payload);
        // 每次只给 100KB —— 模拟慢链路下一段拉不满
        $fetcher = $this->server($payload, 100_000);

        $state = $this->state($hash, '9.9.9', strlen($payload));
        $rounds = 0;
        do {
            $step = uo_download_step(self::URL, $this->part, $state, $hash, '9.9.9', $fetcher);
            self::assertSame('', $step['error']);
            $state = $step['state'];
            $rounds++;
            self::assertLessThan(10, $rounds, '轮次异常，可能没有推进');
        } while (!$step['complete']);

        self::assertSame(strlen($payload), $step['received']);
        self::assertSame($payload, (string) file_get_contents($this->part), '拼起来必须与原包逐字节一致');
        self::assertGreaterThan(1, $rounds, '本用例应当跨多轮完成，否则没测到续传');
    }

    public function testSwitchingPackageDiscardsPartialFile(): void
    {
        file_put_contents($this->part, 'OLD-PACKAGE-BYTES');

        $payload = random_bytes(50_000);
        $hash = hash('sha256', $payload);
        // 旧游标指向另一个包
        $stale = ['url' => self::URL, 'hash' => str_repeat('f', 64), 'version' => '1.0.0', 'total' => 17];

        $step = uo_download_step(self::URL, $this->part, $stale, $hash, '9.9.9', $this->server($payload));

        self::assertSame('', $step['error']);
        self::assertTrue($step['complete']);
        self::assertSame($payload, (string) file_get_contents($this->part), '旧字节必须被丢弃，不能拼接');
    }

    public function testServerIgnoringRangeResetsInsteadOfConcatenating(): void
    {
        $payload = random_bytes(40_000);
        $hash = hash('sha256', $payload);
        file_put_contents($this->part, substr($payload, 0, 10_000));   // 已有前 10KB

        // 假服务端无视 Range，返回 200 + 整包
        $ignoresRange = static function (string $url, int $from, int $to, $fh) use ($payload): array {
            fwrite($fh, $payload);
            return [200, strlen($payload), ''];
        };

        $step = uo_download_step(self::URL, $this->part, $this->state($hash, '9.9.9', strlen($payload)), $hash, '9.9.9', $ignoresRange);

        self::assertNotSame('', $step['error']);
        self::assertTrue($step['no_range']);
        self::assertSame(0, $step['received']);
        clearstatcache(true, $this->part);
        self::assertSame(0, (int) filesize($this->part), '必须清零，不能留下「前半截 + 整包」的拼接');
    }

    public function testTransportErrorWithoutProgressIsReportedAsFailure(): void
    {
        $hash = str_repeat('a', 64);
        $dead = static fn(string $url, int $from, int $to, $fh): array => [0, null, 'Connection timed out'];

        $step = uo_download_step(self::URL, $this->part, $this->state($hash, '9.9.9', 1000), $hash, '9.9.9', $dead);

        self::assertStringContainsString('下载失败', $step['error']);
        self::assertFalse($step['complete']);
    }

    public function testSlowLinkWithPartialProgressIsNotTreatedAsFailure(): void
    {
        $payload = random_bytes(20_000);
        $hash = hash('sha256', $payload);
        // 写了一部分才超时——这是慢链路的常态，不能当失败
        $slow = static function (string $url, int $from, int $to, $fh) use ($payload): array {
            fwrite($fh, substr($payload, $from, 5_000));
            return [0, null, 'Operation timed out'];
        };

        $step = uo_download_step(self::URL, $this->part, $this->state($hash, '9.9.9', strlen($payload)), $hash, '9.9.9', $slow);

        self::assertSame('', $step['error'], '有进展就该继续，不能报失败');
        self::assertSame(5_000, $step['received']);
        self::assertFalse($step['complete']);
    }

    public function testChunkBudgetStaysUnderCommonGatewayTimeout(): void
    {
        // 单次请求必须明显短于国内主机常见的 60 秒网关超时，否则分块就失去意义
        self::assertLessThanOrEqual(30, UO_DOWNLOAD_CHUNK_SECONDS);
        self::assertGreaterThan(0, UO_DOWNLOAD_CHUNK_BYTES);
    }
}
