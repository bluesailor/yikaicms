<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use ErrorLogReader;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ErrorLogReader.php';

final class ErrorLogReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'yk-error-page-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    private function entry(int $id, string $eol = "\n"): string
    {
        return '[2026-09-15 10:00:00] [ERROR] Record-' . $id . ' ' . str_repeat('x', 2100)
            . $eol . '    #0 stack-' . $id . $eol . $eol . '    #1 final-' . $id . $eol;
    }

    public function testEveryHistoricalRecordIsReachableBeyondOldByteAndEntryLimits(): void
    {
        $stream = fopen($this->path, 'wb');
        for ($id = 1; $id <= 350; $id++) {
            fwrite($stream, $this->entry($id, $id % 2 === 0 ? "\r\n" : "\n"));
        }
        fclose($stream);
        self::assertGreaterThan(524288, filesize($this->path));
        $seen = [];
        for ($page = 1; $page <= 12; $page++) {
            $result = ErrorLogReader::readPage($this->path, $page);
            self::assertCount($page < 12 ? 30 : 20, $result['entries']);
            self::assertSame($page < 12, $result['has_more']);
            foreach ($result['entries'] as $entry) {
                preg_match('/Record-(\d+)/', $entry['text'], $matches);
                $id = (int) $matches[1];
                $seen[] = $id;
                self::assertStringContainsString("    #0 stack-$id\n\n    #1 final-$id", $entry['text']);
                self::assertFalse($entry['truncated']);
            }
        }
        self::assertSame(range(350, 1), $seen);
    }

    public function testSnapshotKeepsPagesStableWhenNewEntriesAreAppended(): void
    {
        file_put_contents($this->path, $this->entry(1) . $this->entry(2));
        $first = ErrorLogReader::readPage($this->path, 1, 1);
        file_put_contents($this->path, $this->entry(3), FILE_APPEND);
        $second = ErrorLogReader::readPage($this->path, 2, 1, $first['snapshot']);
        self::assertStringContainsString('Record-1 ', $second['entries'][0]['text']);
        self::assertFalse($second['has_more']);
        self::assertStringContainsString('Record-3 ', ErrorLogReader::readPage($this->path, 1, 1)['entries'][0]['text']);
    }

    public function testTruncationResetsOldSnapshotAndEmptyOutOfRangeIsSafe(): void
    {
        file_put_contents($this->path, $this->entry(1) . $this->entry(2));
        $first = ErrorLogReader::readPage($this->path);
        file_put_contents($this->path, $this->entry(3));
        $result = ErrorLogReader::readPage($this->path, 2, 30, $first['snapshot']);
        self::assertSame(1, $result['page']);
        self::assertStringContainsString('Record-3 ', $result['entries'][0]['text']);
        self::assertSame([], ErrorLogReader::readPage($this->path, PHP_INT_MAX)['entries']);
        file_put_contents($this->path, '');
        self::assertSame([], ErrorLogReader::readPage($this->path)['entries']);
        self::assertFalse(ErrorLogReader::readPage('')['readable']);
        self::assertFalse(ErrorLogReader::readPage($this->path . '-missing')['readable']);
    }

    public function testHugeEntryIsBoundedWithoutHidingOlderRecords(): void
    {
        $stream = fopen($this->path, 'wb');
        fwrite($stream, $this->entry(1) . '[2026-09-15 10:00:00] [ERROR] Very-long-');
        for ($i = 0; $i < 1024; $i++) {
            fwrite($stream, str_repeat('x', 8192));
        }
        // A final entry without a newline must still be displayed.
        fclose($stream);
        $result = ErrorLogReader::readPage($this->path);
        self::assertCount(2, $result['entries']);
        self::assertTrue($result['entries'][0]['truncated']);
        self::assertLessThanOrEqual(65536, strlen($result['entries'][0]['text']));
        self::assertStringContainsString('[ERROR] Very-long-', $result['entries'][0]['text']);
        self::assertStringContainsString('Record-1 ', $result['entries'][1]['text']);
        self::assertFalse($result['entries'][1]['truncated']);
    }

    public function testUtf8PreviewRemainsEscapableAcrossBlockAndTruncationBoundaries(): void
    {
        file_put_contents($this->path, '[2026-09-15 10:00:00] [ERROR] ' . str_repeat("\u{4e2d}\u{6587}", 15000));
        $entry = ErrorLogReader::readPage($this->path)['entries'][0];
        self::assertTrue($entry['truncated']);
        self::assertTrue(mb_check_encoding($entry['text'], 'UTF-8'));
        self::assertLessThanOrEqual(65536, strlen($entry['text']));
        self::assertNotSame('', htmlspecialchars($entry['text'], ENT_QUOTES, 'UTF-8'));
    }
}
