<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 译文来源追踪与保护（评审 T04）：原文改了才算过期、人改过或审过的译文不被整份覆盖、
 * 逐字段采用只写选中的字段且不改发布状态、批量更新只覆盖没人碰过的草稿。
 * 场景在独立进程里跑（完整表结构、假模型），见 tests/fixtures/translation-state-probe.php。
 */
final class TranslationStateTest extends TestCase
{
    /** @return array<string,mixed> */
    private function probe(string $scenario): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/translation-state-probe.php') . ' ' . escapeshellarg($scenario);
        $lines = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, "探针执行失败：\n" . implode("\n", $lines));
        $decoded = json_decode(implode("\n", $lines), true);
        self::assertIsArray($decoded, '探针输出不是 JSON：' . implode("\n", $lines));
        self::assertFalse($decoded['in_transaction'], '事务必须收尾');
        return $decoded;
    }

    public function testOnlyTranslatableSourceChangesMarkATranslationOutdated(): void
    {
        $r = $this->probe('track');
        self::assertSame('machine', $r['link']['state'], '机器译文写入时登记来源');
        self::assertSame(64, strlen((string) $r['link']['source_fp']));
        self::assertSame(['state' => 'current', 'outdated' => false, 'edited' => false, 'reviewed' => false, 'proposal' => false, 'protected' => false], $r['fresh']);
        self::assertFalse($r['non_text_edit']['outdated'], '改价格、排序、浏览数、空参数表的写法不算原文变更');
        self::assertTrue($r['title_edit']['outdated'], '改标题算原文变更');
        self::assertFalse($r['title_edit']['protected'], '过期本身不让机器译文受保护');
    }

    public function testEditedTranslationIsProposedNotOverwrittenAndFieldsAreAcceptedSelectively(): void
    {
        $r = $this->probe('protect_and_accept');
        self::assertTrue($r['before']['edited']);
        self::assertTrue($r['before']['protected']);
        self::assertSame('target_protected', $r['apply']['conflict'], '人改过的译文不整份覆盖');
        self::assertSame('<p>HUMAN</p>', $r['after_apply']['content']);
        self::assertSame('', $r['propose']['conflict']);
        self::assertTrue($r['with_proposal']['proposal']);
        self::assertSame('nothing_selected', $r['accept_none'], '未知字段名不算选择');

        self::assertSame('', $r['accept']);
        self::assertSame('[en] 液压泵 V2', $r['after_accept']['title'], '采用的字段写入');
        self::assertSame('<p>HUMAN</p>', $r['after_accept']['content'], '没勾选的字段保持人工内容');
        self::assertSame(1, (int) $r['after_accept']['status'], '采用不改发布状态');
        self::assertSame('reviewed', $r['link']['state']);
        self::assertSame(7, (int) $r['link']['reviewed_by']);
        self::assertNull($r['link']['proposal_json'], '采用后待审结果清掉');
        self::assertSame(['state' => 'current', 'outdated' => false, 'edited' => false, 'reviewed' => true, 'proposal' => false, 'protected' => true], $r['accepted_status']);
        self::assertSame('no_proposal', $r['accept_again']);
    }

    public function testProposalIsRefusedWhenTheTranslationChangedAfterItWasGenerated(): void
    {
        $r = $this->probe('accept_after_change');
        self::assertSame('target_changed', $r['accept']);
        self::assertSame('[en] 液压泵', $r['after']['title'], '一个字段都不写');
        self::assertSame('<p>HUMAN 2</p>', $r['after']['content']);
    }

    public function testUntrackedTranslationIsProtectedUntilMarkedReviewed(): void
    {
        $r = $this->probe('mark_reviewed');
        self::assertSame('untracked', $r['untracked']['state']);
        self::assertTrue($r['untracked']['protected'], '来历不明的译文按受保护处理');
        self::assertSame('target_protected', $r['untracked_apply']['conflict']);
        self::assertSame('current', $r['reviewed']['state']);
        self::assertTrue($r['reviewed']['reviewed']);
        self::assertTrue($r['reviewed_then_source_edit']['outdated'], '审过之后原文再改，照样提示过期');
        self::assertTrue($r['reviewed_then_source_edit']['reviewed']);
    }

    public function testBatchUpdateOverwritesOnlyUntouchedDraftsAndProposesTheRest(): void
    {
        $r = $this->probe('batch_update');
        self::assertSame(3, $r['created']['total'], '只收过期的已有译文');
        self::assertSame(2, $r['created']['not_outdated'], '没过期的、未追踪的都不排队');
        self::assertSame(['applied' => 1, 'proposed' => 2], $r['counts']);
        self::assertCount(3, $r['calls']);

        self::assertSame('[en] 泵A V2', $r['rows']['A']['title'], '机器写入、没人改过的草稿直接更新');
        self::assertSame('current', $r['rows']['A']['state']['state']);
        self::assertSame('[en] 泵B', $r['rows']['B']['title'], '已发布的不覆盖');
        self::assertSame(1, (int) $r['rows']['B']['status'], '也不改回草稿');
        self::assertTrue($r['rows']['B']['state']['proposal']);
        self::assertSame('<p>HUMAN</p>', $r['rows']['C']['content'], '人改过的不覆盖');
        self::assertTrue($r['rows']['C']['state']['proposal']);
        self::assertSame('[en] 泵D', $r['rows']['D']['title']);
        self::assertSame('[en] 泵E', $r['rows']['E']['title']);
        self::assertSame(1, $r['again']['total'], '已更新的不再排队，待审的仍过期可再排');
    }
}
