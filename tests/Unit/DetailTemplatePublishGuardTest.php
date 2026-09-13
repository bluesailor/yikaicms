<?php
/**
 * 第三轮发布冲突保护的纯函数部分：前后对比、条件签名、扫描范围、分页进度合并。
 * 真实内容扫描、锁与发布接口由 e2e 覆盖。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailTemplatePublishGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** @param list<int> $ids */
    private static function tie(array $ids): array
    {
        return ['conflicts' => array_map(static fn (int $id): array => ['template_id' => $id, 'dimension' => 'same_specificity_and_priority'], $ids)];
    }

    /** @return array<string,mixed> */
    private static function scope(array $overrides = []): array
    {
        return array_merge([
            'version' => 2, 'content_type' => 'product', 'lang' => 'zh-CN', 'source' => 'custom', 'priority' => 0,
            'include' => [['kind' => 'all', 'ids' => [], 'include_children' => false]], 'exclude' => [],
        ], $overrides);
    }

    public function testNewTieWithThisTemplateIsBlocked(): void
    {
        $this->assertSame('member', DetailTemplatePublishGuard::classify(self::tie([]), self::tie([4, 7]), 7, true));
        $this->assertNull(DetailTemplatePublishGuard::classify(self::tie([]), self::tie([]), 7, true), '没有并列就放行');
    }

    public function testUnchangedConditionsKeepHistoricalTiesCompatible(): void
    {
        // 条件没变、并列成员也没变：历史冲突不因一次版式发布而被突然锁死
        $this->assertNull(DetailTemplatePublishGuard::classify(self::tie([4, 7]), self::tie([4, 7]), 7, false));
        // 条件改了但仍在并列里：不能拿"历史上就并列"做挡箭牌
        $this->assertSame('member', DetailTemplatePublishGuard::classify(self::tie([4, 7]), self::tie([4, 7]), 7, true));
        // 条件没变但并列成员变了（例如别人刚发布了一个同级模板后本模板重新发布）
        $this->assertSame('member', DetailTemplatePublishGuard::classify(self::tie([4, 7]), self::tie([4, 7, 9]), 7, false));
    }

    public function testExposingATieAmongOtherTemplatesIsReported(): void
    {
        // 发布前本模板更具体、单独命中；发布后它不再覆盖这条内容，其它两个模板的并列开始生效
        $this->assertSame('exposed', DetailTemplatePublishGuard::classify(self::tie([]), self::tie([3, 5]), 7, true));
        $this->assertNull(DetailTemplatePublishGuard::classify(self::tie([3, 5]), self::tie([3, 5]), 7, true), '其它模板原本就并列：不是本次引入');
    }

    public function testConditionSignatureIgnoresOutputSourceButNotRules(): void
    {
        $base = self::scope();
        $this->assertSame(
            DetailTemplatePublishGuard::conditionSignature($base),
            DetailTemplatePublishGuard::conditionSignature(self::scope(['source' => 'native'])),
            '切换 native/custom 不改变并列'
        );
        $this->assertNotSame(DetailTemplatePublishGuard::conditionSignature($base), DetailTemplatePublishGuard::conditionSignature(self::scope(['priority' => 1])));
        $this->assertNotSame(
            DetailTemplatePublishGuard::conditionSignature($base),
            DetailTemplatePublishGuard::conditionSignature(self::scope(['exclude' => [['kind' => 'item', 'ids' => [3], 'include_children' => false]]]))
        );
        $legacy = DetailTemplateResolver::legacyScope(['mode' => 'all', 'lang' => 'zh-CN']);
        $this->assertNotSame(DetailTemplatePublishGuard::conditionSignature($legacy), DetailTemplatePublishGuard::conditionSignature($base), 'v1 转 v2 属于修改条件');
        $this->assertSame('', DetailTemplatePublishGuard::conditionSignature(null));
    }

    public function testDomainCoversOldAndNewRulesWithoutUsingExcludes(): void
    {
        $descendants = static fn (int $id): array => $id === 5 ? [5, 8, 9] : [$id];
        $old = self::scope(['include' => [['kind' => 'item', 'ids' => [12], 'include_children' => false]]]);
        $new = self::scope([
            'include' => [['kind' => 'category', 'ids' => [5], 'include_children' => true], ['kind' => 'category', 'ids' => [6], 'include_children' => false]],
            'exclude' => [['kind' => 'item', 'ids' => [99], 'include_children' => false]],
        ]);
        $domain = DetailTemplatePublishGuard::domain('product', [$old, $new], $descendants);
        $this->assertSame(['zh-CN'], $domain['langs']);
        $this->assertFalse($domain['all']);
        $this->assertSame([12], $domain['items'], '旧版本覆盖的内容也要检查（发布后可能暴露其它并列）');
        $this->assertSame([5, 6, 8, 9], $domain['categories'], '含子级展开，不含子级只取自身');

        $wide = DetailTemplatePublishGuard::domain('product', [$old, self::scope()], $descendants);
        $this->assertTrue($wide['all']);
        $this->assertSame([], $wide['items']);

        $none = DetailTemplatePublishGuard::domain('product', [null, self::scope(['include' => []]), self::scope(['content_type' => 'article'])], $descendants);
        $this->assertSame([], $none['langs'], '不应用、类型不符的条件不贡献范围');
    }

    public function testProgressOnlyAllowsPublishWhenCompleteCleanAndCurrent(): void
    {
        $page = static fn (int $scanned, int $next, bool $complete, int $found = 0): array => [
            'total' => 5, 'scanned' => $scanned, 'next' => $next, 'complete' => $complete, 'found' => $found, 'conflicts' => [],
        ];
        $first = DetailTemplatePublishGuard::mergeProgress(null, 'fp-1', $page(2, 20, false));
        $this->assertFalse(DetailTemplatePublishGuard::progressAllowsPublish($first, 'fp-1'), '未扫完不放行');

        $second = DetailTemplatePublishGuard::mergeProgress($first, 'fp-1', $page(3, 50, true));
        $this->assertSame(5, $second['scanned']);
        $this->assertSame(50, $second['next']);
        $this->assertTrue(DetailTemplatePublishGuard::progressAllowsPublish($second, 'fp-1'));
        $this->assertFalse(DetailTemplatePublishGuard::progressAllowsPublish($second, 'fp-2'), '候选或条件变了就不再信任');

        $restarted = DetailTemplatePublishGuard::mergeProgress($second, 'fp-2', null);
        $this->assertSame(0, $restarted['next'], '指纹变化从头开始');
        $this->assertFalse($restarted['complete']);

        $dirty = DetailTemplatePublishGuard::mergeProgress($first, 'fp-1', $page(3, 50, true, 1));
        $this->assertFalse(DetailTemplatePublishGuard::progressAllowsPublish($dirty, 'fp-1'), '前面某页发现过并列就不放行');
    }

    public function testInjectedLegacyDraftKeepsHistoricalTieSemantics(): void
    {
        $legacy = DetailTemplateResolver::legacyScope(['mode' => 'all', 'lang' => 'zh-CN']);
        $candidates = DetailTemplateProvider::injectDraft([], 'product', 7, $legacy);
        $this->assertTrue($candidates[0]['scope']['legacy'], '未迁移的 v1 草稿注入后仍按 v1 并列语义判定');
    }
}
