<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AI 翻译 / 重新翻译：模型请求进行中别人改了内容，写库时不能覆盖（评审 F01 / T01）。
 * 场景在独立进程里跑（完整表结构），见 tests/fixtures/translate-action-race-probe.php。
 */
final class TranslateActionRaceTest extends TestCase
{
    /** @return array<string,mixed> */
    private function probe(string $scenario): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/translate-action-race-probe.php') . ' ' . escapeshellarg($scenario);
        $lines = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, "探针执行失败：\n" . implode("\n", $lines));
        $decoded = json_decode(implode("\n", $lines), true);
        self::assertIsArray($decoded, '探针输出不是 JSON：' . implode("\n", $lines));
        self::assertFalse($decoded['in_transaction'], '事务必须收尾');
        return $decoded;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function row(array $r, string $table, int $id): array
    {
        foreach ($r[$table] as $row) if ((int) $row['id'] === $id) return $row;
        self::fail("{$table}#{$id} 不存在");
    }

    /** @param array<string,mixed> $r */
    private function assertConflict(array $r, string $code): void
    {
        self::assertArrayHasKey('ok', $r, (string) ($r['error'] ?? ''));
        self::assertSame($code, $r['ok']['conflict']);
        self::assertSame([], $r['revisions'], '冲突时不留历史版本');
    }

    public function testPlainRetranslationWrites(): void
    {
        $r = $this->probe('plain');
        self::assertSame('', $r['ok']['conflict']);
        self::assertSame('<p>Specifications</p>', $this->row($r, 'products', $r['target'])['content']);
        // 评审 F09：被覆盖的产品译文存进产品编辑器自己的历史（product_edit），可在产品编辑页恢复
        self::assertCount(1, $r['revisions']);
        self::assertSame(['product_edit', $r['target']], [$r['revisions'][0]['target_type'], (int) $r['revisions'][0]['target_id']]);
        $saved = json_decode($r['revisions'][0]['snapshot'], true)['fields'];
        self::assertSame(['Old pump', 'Old', '<p>Old</p>'], [$saved['title'], $saved['summary'], $saved['content']]);
    }

    public function testSinglePageRetranslationKeepsTheOverwrittenTextInHistory(): void
    {
        $r = $this->probe('page_plain');
        self::assertSame('', $r['ok']['conflict']);
        self::assertSame('Hydraulic pump', $this->row($r, 'channels', $r['target'])['name']);
        self::assertSame('<p>Specifications</p>', $this->row($r, 'contents', $r['target_page'])['content']);
        $saved = [];
        foreach ($r['revisions'] as $revision) {
            foreach (json_decode($revision['snapshot'], true)['targets'] as $t) $saved[$t['table']] = $t['fields'];
        }
        self::assertSame('About', $saved['channels']['name']);
        self::assertSame('<p>Old</p>', $saved['contents']['content']);
    }

    public function testVisitCountersAreNotEdits(): void
    {
        $r = $this->probe('views_bump');
        self::assertSame('', $r['ok']['conflict']);
        self::assertSame('<p>Specifications</p>', $this->row($r, 'products', $r['target'])['content']);
    }

    public function testHumanEditDuringTheModelCallIsNotOverwritten(): void
    {
        $r = $this->probe('edit_target');
        $this->assertConflict($r, 'target_changed');
        self::assertSame('<p>HUMAN</p>', $this->row($r, 'products', $r['target'])['content']);
    }

    public function testPublishingDuringTheModelCallIsNotUndone(): void
    {
        $r = $this->probe('publish_target');
        $this->assertConflict($r, 'target_published');
        $target = $this->row($r, 'products', $r['target']);
        self::assertSame(1, (int) $target['status']);
        self::assertSame('<p>Old</p>', $target['content']);
    }

    public function testDeletionDuringTheModelCallIsNotRevived(): void
    {
        $r = $this->probe('delete_target');
        $this->assertConflict($r, 'target_deleted');
        $target = $this->row($r, 'products', $r['target']);
        self::assertNotNull($target['deleted_at']);
        self::assertSame('<p>Old</p>', $target['content']);
    }

    public function testSourceEditDuringTheModelCallMakesTheTranslationStale(): void
    {
        $r = $this->probe('source_changed');
        $this->assertConflict($r, 'source_changed');
        self::assertSame('<p>Old</p>', $this->row($r, 'products', $r['target'])['content']);
    }

    public function testConcurrentCreationDoesNotDuplicateTheLanguage(): void
    {
        $r = $this->probe('create_race');
        $this->assertConflict($r, 'target_exists');
        self::assertSame($r['rival'], $r['ok']['id']);
        $english = array_filter($r['products'], static fn (array $row): bool => $row['lang'] === 'en');
        self::assertCount(1, $english);
    }

    public function testRecycledTranslationDoesNotBlockANewOne(): void
    {
        $r = $this->probe('recycled_create');
        self::assertSame('', $r['ok']['conflict']);
        self::assertNotSame($r['rival'], $r['ok']['id']);
        $live = array_filter($r['products'], static fn (array $row): bool => $row['lang'] === 'en' && $row['deleted_at'] === null);
        self::assertCount(1, $live);
    }

    public function testSinglePageBodyEditBlocksBothTables(): void
    {
        $r = $this->probe('page_child_edit');
        $this->assertConflict($r, 'target_changed');
        self::assertSame('<p>HUMAN</p>', $this->row($r, 'contents', $r['target_page'])['content']);
        self::assertSame('About', $this->row($r, 'channels', $r['target'])['name']);
    }
}
