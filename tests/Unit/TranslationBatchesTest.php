<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 批量翻译任务（评审 T03）：中断可恢复、不重复调模型、不重复写、冲突不覆盖、失败可见。
 * 场景在独立进程里跑（完整表结构、假模型），见 tests/fixtures/translation-batches-probe.php。
 */
final class TranslationBatchesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function probe(string $scenario): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/translation-batches-probe.php') . ' ' . escapeshellarg($scenario);
        $lines = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, "探针执行失败：\n" . implode("\n", $lines));
        $decoded = json_decode(implode("\n", $lines), true);
        self::assertIsArray($decoded, '探针输出不是 JSON：' . implode("\n", $lines));
        self::assertFalse($decoded['in_transaction'], '事务必须收尾');
        return $decoded;
    }

    public function testBatchTranslatesEveryProductLanguagePairIntoDrafts(): void
    {
        $r = $this->probe('happy');
        self::assertSame(4, $r['created']['total'], '站点默认语言不算目标语言');
        self::assertSame(['applied' => 4], $r['counts']);
        self::assertSame('done', $r['batch']);
        self::assertCount(4, $r['calls'], '全部完成后再跑一次不再调模型');
        foreach ($r['translations'] as $row) {
            self::assertSame(0, (int) $row['status'], '译文落草稿');
            self::assertStringEndsWith('-' . $row['lang'], $row['slug']);
            self::assertContains((int) $row['translation_group_id'], $r['groups']);
        }
    }

    public function testGeneratedResultSurvivesACrashAndIsWrittenWithoutCallingTheModel(): void
    {
        $r = $this->probe('resume_after_crash');
        self::assertSame([], $r['calls']);
        self::assertSame('applied', $r['items'][0]['status']);
        self::assertSame([['title' => 'Saved earlier']], $r['translations']);
    }

    public function testLeasedItemIsLeftAloneUntilTheLeaseExpires(): void
    {
        $r = $this->probe('lease');
        self::assertSame(['generating' => 1], $r['while_leased']);
        self::assertSame(0, $r['calls_while_leased']);
        self::assertSame(['applied' => 1], $r['after_expiry']);
        self::assertSame(2, $r['items'][0]['attempts'], '被杀掉的那次也算一次尝试');
    }

    public function testFailuresAreCountedVisibleAndRetryable(): void
    {
        $r = $this->probe('failures');
        self::assertSame(['pending', 'pending', 'pending', 'pending', 'failed', 'failed'], array_column($r['after'], 'status'));
        self::assertSame([1, 1, 2, 2, 3, 3], array_column($r['after'], 'calls'), '退避期内不调模型；满 3 次后不再调');
        self::assertSame(1800000000 + 1 + 60, $r['after'][0]['lease_until'], '第 1 次失败后等 60 秒');
        self::assertSame(1800000000 + 62 + 120, $r['after'][2]['lease_until'], '第 2 次失败后等 120 秒');
        self::assertSame(3, $r['after'][5]['attempts']);
        self::assertSame('provider 503', $r['after'][5]['error']);
        self::assertCount(3, $r['calls']);
        self::assertSame(['created', 'retry_later', 'retry_later', 'failed', 'done'], $r['log']);
        self::assertSame('done', $r['batch']);
        self::assertSame(1, $r['retried']);
        self::assertSame(['pending', 0, ''], [$r['after_retry']['status'], $r['after_retry']['attempts'], $r['after_retry']['error']]);
        self::assertSame('running', $r['batch_after_retry']);
    }

    public function testSourceEditDuringGenerationIsAConflictAndRetryRegenerates(): void
    {
        $r = $this->probe('source_changed');
        self::assertSame(['conflict', 'source_changed'], [$r['after_change']['status'], $r['after_change']['error']]);
        self::assertSame(0, $r['en_rows_after_change']);
        self::assertSame('applied', $r['after_retry']['status']);
        self::assertSame(['液压泵→en', '液压泵 V2→en'], $r['calls'], '重试用的是改过的原文');
    }

    public function testTranslationCreatedByHandMeanwhileIsNotDuplicated(): void
    {
        $r = $this->probe('created_elsewhere');
        self::assertSame('exists', $r['items'][0]['status']);
        self::assertSame((int) $r['en_rows'][0]['id'], $r['items'][0]['target_id']);
        self::assertCount(1, $r['en_rows']);
    }

    public function testCreationSkipsExistingQueuedAndNonSourceProducts(): void
    {
        $r = $this->probe('create_rules');
        self::assertSame(1, $r['second']['queued_elsewhere'], '同一产品同一语言已在进行中的批次里');
        self::assertSame(2, $r['second']['not_source'], '非默认语言的产品和不存在的 id');
        self::assertSame([['ja', 'pending'], ['en', 'exists'], ['ja', 'pending']], array_map(static fn (array $i): array => [$i['lang'], $i['status']], $r['second_items']));
        self::assertSame('translation_batch_empty', $r['empty']);
    }

    public function testPausedAndCancelledBatchesDoNotRun(): void
    {
        $r = $this->probe('pause_cancel');
        self::assertSame(['pending' => 2], $r['paused_step']);
        self::assertSame(0, $r['calls_paused']);
        self::assertSame('running', $r['resumed_status']);
        self::assertSame(['cancelled' => 2], $r['cancelled_step']);
        self::assertSame(0, $r['calls_cancelled']);
        self::assertSame('cancelled', $r['batch']);
    }
    public function testBackgroundDrainRunsOnlyRunningBatchesAndStopsNearTheMemoryLimit(): void
    {
        $r = $this->probe('drain');
        self::assertTrue($r['order'], '按批次号推进，暂停的批次不在结果里');
        self::assertSame(['done', 'paused', 'done'], $r['statuses']);
        self::assertSame(3, $r['calls'], '暂停批次的项不调模型');
        self::assertSame([], $r['again'], '没有进行中的批次时是一次早退');
        self::assertSame([false, true, false, false, true], $r['memory'], '85% 为界；memory_limit=-1 不限');
    }

    public function testCallLimitPausesTheBatchAndResumeGrantsMore(): void
    {
        $r = $this->probe('budget');
        self::assertSame(['applied' => 2, 'pending' => 1], $r['first']);
        self::assertSame(['paused', 'budget', 2, 2, 2], $r['paused'], '到上限即暂停，不多调一次模型');
        self::assertSame(['pending', 0, 0], $r['released'][2], '放回去的项不算一次尝试、可以马上再领');
        self::assertSame(['running', '', 2 + 3], $r['resumed'], '继续时给剩下 1 项再分配 3 次');
        self::assertSame(['applied' => 3], $r['second']);
        self::assertSame(['done', 3, 3], $r['done']);
        self::assertSame(['created', 'budget_paused', 'resumed', 'done'], $r['log']);
        self::assertSame(2 * 3, $r['default_cap'], '没设上限时按每项 3 次封顶');
    }

    public function testPurgeRemovesOnlyExpiredFinishedBatchesAndKeepsTranslations(): void
    {
        $r = $this->probe('purge');
        self::assertSame(2, $r['purged']);
        self::assertSame($r['expected_left'], $r['left'], '暂停的与未过期的批次保留');
        self::assertSame(0, $r['orphans'], '项与日志一起删');
        self::assertTrue($r['translations_kept'], '已写进站点的译文不受影响');
        self::assertSame(0, $r['again']);
    }

    public function testCancellationDuringGenerationWinsOverTheLateResult(): void
    {
        foreach (["cancel_during_generation", "cancel_then_provider_error"] as $scenario) {
            $r = $this->probe($scenario);
            self::assertSame(["cancelled" => 2], $r["counts"], "{$scenario}：晚到的结果或报错都不能让项目复活（评审 F03）");
            self::assertSame("cancelled", $r["batch"]);
            self::assertSame(0, $r["en_rows"], "{$scenario}：不写译文");
            self::assertSame(0, $r["links"], "{$scenario}：不记译文来源");
            self::assertSame(["cancelled" => 2], $r["again"]);
            self::assertCount(1, $r["calls"]);
        }
    }

    public function testAResultFromAnExpiredLeaseDoesNotOverwriteTheNewHolder(): void
    {
        $r = $this->probe("stale_lease_holder");
        self::assertSame($r["rival"], array_intersect_key($r["items"][0], $r["rival"]), "新领取者的状态、次数、租约原样保留");
        self::assertNull($r["result_saved"], "旧结果不落库");
        self::assertSame(0, $r["en_rows"]);
    }

    public function testCancellingAfterAnItemWasWrittenLeavesItApplied(): void
    {
        $r = $this->probe("cancel_after_apply");
        self::assertSame("applied", $r["items"][0]["status"]);
        self::assertSame(1, $r["en_rows"]);
    }
}
