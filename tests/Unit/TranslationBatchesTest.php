<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/TranslationBatches.php';

/**
 * 批量翻译任务（评审 T03）：中断可恢复、不重复调模型、不重复写、冲突不覆盖、失败可见。
 * 场景在独立进程里跑（完整表结构、假模型），见 tests/fixtures/translation-batches-probe.php。
 */
final class TranslationBatchesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function probe(string $scenario): array
    {
        // 用模拟服务商的场景较慢，同一进程里只跑一次
        static $cache = [];
        if ($scenario === 'request_failures' && isset($cache[$scenario])) return $cache[$scenario];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/translation-batches-probe.php') . ' ' . escapeshellarg($scenario);
        $lines = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, "探针执行失败：\n" . implode("\n", $lines));
        $decoded = json_decode(implode("\n", $lines), true);
        self::assertIsArray($decoded, '探针输出不是 JSON：' . implode("\n", $lines));
        self::assertFalse($decoded['in_transaction'], '事务必须收尾');
        return $cache[$scenario] = $decoded;
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
        self::assertSame(['paused', 'budget', 2, 2, 2], $r['paused'], '到上限即暂停，不多发一次请求');
        self::assertSame(['pending', 0, 0], $r['released'][2], '放回去的项不算一次尝试、可以马上再领');
        self::assertSame(['running', '', 2 + TranslationBatches::REQUESTS_PER_ITEM], $r['resumed'], '继续时给剩下 1 项再分配一项的请求额度');
        self::assertSame(['applied' => 3], $r['second']);
        self::assertSame(['done', 3, 3], $r['done']);
        self::assertSame(['created', 'budget_paused', 'resumed', 'done'], $r['log']);
        self::assertSame(2 * TranslationBatches::REQUESTS_PER_ITEM, $r['default_cap'], '没设上限时按每项 REQUESTS_PER_ITEM 次请求封顶');
    }

    /**
     * 2.3.1（11-26 评审第一张工单）：额度按真正发出的模型请求计。真实 AiService 对本地模拟服务商，以服务商自己的请求日志为准。
     */
    /**
     * 服务商出错（500、回复发到一半断开）：出错的那次算一次已发出的请求、一次尝试；已拿到的回复留着，重试只补发出错的那一次。
     * 回复不合格：存下的回复作废，重试从头再问。以模拟服务商自己的请求日志为准。
     */
    public function testEveryReplyIsSavedAsItArrivesAndClearedOnceTheItemIsDone(): void
    {
        $r = $this->probe('checkpoint');
        self::assertSame([1, 2], $r['seen'], '每个成功回复到达后立刻存进这一项');
        self::assertNull($r['after'], '生成完成后清掉');
    }

    public function testProviderFailuresCountEverySentRequestAndRetryOnlyTheMissingReply(): void
    {
        $r = $this->probe('request_failures');
        $base = $r['baseline'];
        self::assertSame(['applied', $base['mock']], [$base['item'], $base['batch']['requests']]);
        foreach (['http_500', 'cut'] as $case) {
            $f = $r[$case];
            self::assertSame([2, 2, 1, 'pending', 1], [$f['first']['mock'], $f['first']['batch']['requests'], $f['first']['batch']['calls'], $f['first']['item']['status'], $f['first']['saved_replies']], $case);
            self::assertSame('running', $f['first']['batch']['status'], $case . '：出错不暂停整批，这一项退避后重试');
            self::assertSame([1, 2], $f['sent'], $case . '：第 1 个回复重放，只补发出错的第 2 个');
            self::assertSame($f['final']['mock'], $f['final']['batch']['requests'], $case . '：批次计数 = 服务商收到的请求');
            self::assertSame($f['final']['distinct'] + 1, $f['final']['mock'], $case);
            self::assertSame(['applied', 2], [$f['final']['item']['status'], $f['final']['batch']['calls']], $case);
        }
        self::assertSame($base['mock'] + 1, $r['http_500']['final']['mock'], '同一条内容：比不出错时只多 1 次');
        self::assertStringContainsString('cURL', $r['cut']['first']['item']['error'], '结果未知的请求照样计为已发出');

        $bad = $r['bad_reply'];
        self::assertSame(0, $bad['first']['saved_replies'], '回复不合格：存下的回复作废');
        self::assertSame($bad['first']['mock'], $bad['first']['batch']['requests']);
        self::assertSame(2, $bad['sent'][0], '重试从头再问');
        self::assertSame($bad['final']['mock'], $bad['final']['batch']['requests']);
        self::assertSame('applied', $bad['final']['item']['status']);
    }

    public function testPublishedTranslationIsUntouchedWhileAnUpdateBatchIsPausedForQuota(): void
    {
        $r = $this->probe('request_failures');
        $p = $r['published_paused'];
        self::assertSame(1, $p['created_total']);
        self::assertSame(['translate'], $p['mock'], '额度 1：只发首轮，不发校对');
        self::assertSame(['paused', 'budget', 1, 0], [$p['batch']['status'], $p['batch']['note'], $p['batch']['requests'], $p['batch']['calls']]);
        self::assertSame(['pending', 0], [$p['item']['status'], $p['item']['attempts']]);
        self::assertTrue($p['unchanged'], '暂停期间已发布的译文一个字段都不动');
        $d = $r['published_done'];
        self::assertSame(['translate', 'review'], $d['mock'], '继续后首轮重放，只补发校对');
        self::assertSame(['done', 2, 1], [$d['batch']['status'], $d['batch']['requests'], $d['batch']['calls']]);
        self::assertSame('proposed', $d['item']['status']);
        self::assertTrue($d['unchanged'], '已发布、审过的译文只存为待审');
        self::assertSame(1, $d['proposal']);
    }

    public function testQuotaCountsEveryProviderRequestAndResumeDoesNotResend(): void
    {
        $r = $this->probe('requests');
        // 额度 1、短标题、默认校对：服务商只收到 1 次请求，在校对前暂停，什么都没写
        self::assertSame(['translate'], $r['short_paused']['mock']);
        self::assertSame(['calls' => 0, 'requests' => 1, 'max_calls' => 1, 'requests_known' => 1, 'status' => 'paused', 'note' => 'budget'], $r['short_paused']['batch']);
        self::assertSame(['pending', 0, 1, 0], [$r['short_paused']['item'], $r['short_paused']['attempts'], $r['short_paused']['saved_replies'], $r['short_paused']['en_rows']],
            '这一项放回、不算尝试；首轮译文存下；没写任何译文');
        // 提高额度后继续：首轮译文重放、不再发，只补发校对
        self::assertSame(['translate', 'review'], $r['short_done']['mock']);
        self::assertSame(['done', 2, 1], [$r['short_done']['batch']['status'], $r['short_done']['batch']['requests'], $r['short_done']['batch']['calls']]);
        self::assertSame(['applied', 0], [$r['short_done']['item'], $r['short_done']['saved_replies']], '写完后存下的回复清掉');
        self::assertStringStartsWith('Translated ', $r['short_done']['en_title']);

        // 长描述：分段多、几次暂停后继续，每个请求只发一次，批次计数与服务商收到的请求数一致
        self::assertSame(['paused', 'budget', 3, 3, 3], array_values($r['long_paused']));
        self::assertSame($r['long_done']['mock'], $r['long_done']['distinct'], '继续时不重发已经成功的请求');
        self::assertSame($r['long_done']['mock'], $r['long_done']['batch']['requests']);
        self::assertGreaterThan(4, $r['long_done']['mock']);
        self::assertSame(['done', 'applied'], [$r['long_done']['batch']['status'], $r['long_done']['item']]);

        // 不支持 JSON 输出的服务商：被拒的那次和去掉 JSON 后的重发都算
        self::assertSame([1, 1, 'paused'], [$r['strict_paused']['mock'], $r['strict_paused']['batch']['requests'], $r['strict_paused']['batch']['status']]);
        self::assertSame($r['strict_done']['mock'], $r['strict_done']['batch']['requests']);
        self::assertSame(['done', 'applied'], [$r['strict_done']['batch']['status'], $r['strict_done']['item']]);
    }

    public function testTwoWorkersCannotBothTakeTheLastRequest(): void
    {
        $r = $this->probe('concurrent');
        self::assertSame([true, false, false], $r['reserved']);
        self::assertSame(1, $r['requests']);
    }

    public function testMigrationMarksExistingBatchesAsUnknownAndIsRepeatable(): void
    {
        $r = $this->probe('migration');
        self::assertFalse($r['check_before']);
        self::assertTrue($r['check_after']);
        self::assertSame([['calls' => 4, 'requests' => 0, 'requests_known' => 0], ['calls' => 0, 'requests' => 0, 'requests_known' => 1]], $r['rows'],
            '升级前的批次保留生成次数、请求数记为未知；迁移再跑一次不影响新批次');
    }

    public function testSavedRepliesAreDiscardedUnlessWellFormed(): void
    {
        self::assertSame([1, 0, 0, 0, 0], $this->probe('partial-decode')['decoded']);
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
