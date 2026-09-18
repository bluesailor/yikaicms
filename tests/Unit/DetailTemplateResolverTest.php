<?php
/**
 * DetailTemplateResolver（v2 详情模板条件判定）契约测试。
 *
 * 覆盖任务书 DS-BLOX-01A/01D 要求的四项：归一化、畸形条件 fail-closed、
 * 旧规则（v1 product_template）只读兼容、确定性排序与冲突暴露。
 */
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class DetailTemplateResolverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** @return array<string,mixed> */
    private static function candidate(int $id, array $scopeOverrides = [], string $type = 'product-detail', int $status = 1): array
    {
        $scope = array_merge([
            'version' => 2,
            'content_type' => 'product',
            'lang' => 'zh-CN',
            'include' => [['kind' => 'all']],
        ], $scopeOverrides);

        return ['id' => $id, 'type' => $type, 'status' => $status, 'lang' => 'zh-CN', 'scope' => DetailTemplateResolver::normalizeScope($scope)];
    }

    /** @return array<string,mixed> */
    private static function ctx(array $overrides = []): array
    {
        return array_merge([
            'content_type' => 'product',
            'content_id' => 12,
            'lang' => 'zh-CN',
            'channel_type' => 'product',
            'categories' => [['id' => 5, 'distance' => 0], ['id' => 3, 'distance' => 1]],
        ], $overrides);
    }

    // ---------- 归一化 ----------

    public function testScopeNormalizationIsFailClosed(): void
    {
        $empty = DetailTemplateResolver::normalizeScope(null);
        $this->assertSame('', $empty['content_type']);
        $this->assertSame([], $empty['include']);
        $this->assertFalse(DetailTemplateResolver::scopeIsUsable($empty));

        // 未知内容类型 / 缺语言 / 未知版本 / 非数组 一律不可用
        $this->assertFalse(DetailTemplateResolver::scopeIsUsable(
            DetailTemplateResolver::normalizeScope(['content_type' => 'whatever', 'lang' => 'zh-CN', 'include' => [['kind' => 'all']]])
        ));
        $this->assertFalse(DetailTemplateResolver::scopeIsUsable(
            DetailTemplateResolver::normalizeScope(['content_type' => 'product', 'include' => [['kind' => 'all']]])
        ));
        $this->assertFalse(DetailTemplateResolver::scopeIsUsable(
            DetailTemplateResolver::normalizeScope(['version' => 3, 'content_type' => 'product', 'lang' => 'zh-CN', 'include' => [['kind' => 'all']]])
        ));
        $this->assertFalse(DetailTemplateResolver::scopeIsUsable(DetailTemplateResolver::normalizeScope('nope')));
    }

    public function testScopeRejectsMalformedRulesInsteadOfWidening(): void
    {
        // 空 ids 的 item/category 规则被丢弃（不是 all）；exclude 不接受 all
        $scope = DetailTemplateResolver::normalizeScope([
            'content_type' => 'product', 'lang' => 'zh-CN',
            'include' => [['kind' => 'item', 'ids' => []], ['kind' => 'category', 'ids' => ['x', 0, -2]], ['kind' => 'oops', 'ids' => [1]]],
            'exclude' => [['kind' => 'all'], ['kind' => 'item', 'ids' => ['7']]],
        ]);
        $this->assertSame([], $scope['include']);
        $this->assertSame([['kind' => 'item', 'ids' => [7], 'include_children' => false]], $scope['exclude']);
    }

    public function testScopeNormalizesIdsPriorityAndSource(): void
    {
        $scope = DetailTemplateResolver::normalizeScope([
            'content_type' => 'product', 'lang' => 'zh-CN', 'priority' => 999, 'source' => 'native',
            'include' => [['kind' => 'item', 'ids' => ['12', 12, '0012', 0, 'abc', 13, true, 2.5]]],
        ]);
        $this->assertSame([12, 13], $scope['include'][0]['ids']);   // 去重 + 只留正整数
        $this->assertSame(100, $scope['priority']);                  // clamp 到上限
        $this->assertSame('native', $scope['source']);
        $this->assertSame('custom', DetailTemplateResolver::normalizeScope(
            ['content_type' => 'product', 'lang' => 'zh-CN', 'source' => 'anything', 'include' => [['kind' => 'all']]]
        )['source']);
        $this->assertTrue($scope['include'][0]['include_children'] === false);
    }

    public function testBindingNormalization(): void
    {
        $this->assertSame(['mode' => 'auto', 'template_id' => 0], DetailTemplateResolver::normalizeBinding(null));
        $this->assertSame(['mode' => 'auto', 'template_id' => 0], DetailTemplateResolver::normalizeBinding(['mode' => 'nope', 'template_id' => 9]));
        $this->assertSame(['mode' => 'template', 'template_id' => 7], DetailTemplateResolver::normalizeBinding(['mode' => 'template', 'template_id' => '7']));
        // template 模式但 id 非法：保留模式，交由 resolve() 报失效
        $this->assertSame(['mode' => 'template', 'template_id' => 0], DetailTemplateResolver::normalizeBinding(['mode' => 'template', 'template_id' => 'abc']));
        // auto/native 不保留 template_id
        $this->assertSame(['mode' => 'native', 'template_id' => 0], DetailTemplateResolver::normalizeBinding(['mode' => 'native', 'template_id' => 7]));
    }

    // ---------- 旧规则只读兼容 ----------

    public function testLegacyScopeKeepsHistoricalSemantics(): void
    {
        $legacySelected = DetailTemplateResolver::legacyScope(['mode' => 'selected', 'ids' => ['12'], 'lang' => 'zh-CN']);
        $this->assertTrue($legacySelected['legacy']);
        $this->assertSame('product', $legacySelected['content_type']);
        $this->assertSame([['kind' => 'item', 'ids' => [12], 'include_children' => false]], $legacySelected['include']);

        // v1 的 selected + 空 ids ＝ 未应用（不是 all）
        $legacyEmpty = DetailTemplateResolver::legacyScope(['mode' => 'selected', 'ids' => [], 'lang' => 'zh-CN']);
        $this->assertSame([], $legacyEmpty['include']);
        $this->assertFalse(DetailTemplateResolver::scopeIsUsable($legacyEmpty));

        $legacyNative = DetailTemplateResolver::legacyScope(['mode' => 'all', 'lang' => 'zh-CN', 'source' => 'native']);
        $this->assertSame('native', $legacyNative['source']);
        $this->assertSame('all', $legacyNative['include'][0]['kind']);
    }

    public function testLegacyTieKeepsHighestTemplateIdWithoutConflict(): void
    {
        $legacyA = ['id' => 9, 'type' => 'product-detail', 'status' => 1, 'scope' => DetailTemplateResolver::legacyScope(['mode' => 'selected', 'ids' => [12], 'lang' => 'zh-CN'])];
        $legacyB = ['id' => 4, 'type' => 'product-detail', 'status' => 1, 'scope' => DetailTemplateResolver::legacyScope(['mode' => 'selected', 'ids' => [12], 'lang' => 'zh-CN'])];

        $result = DetailTemplateResolver::resolve([$legacyB, $legacyA], self::ctx());
        $this->assertSame(9, $result['template_id']);          // 历史语义：同级取最大模板 ID
        $this->assertSame('specific_item', $result['reason']);
        $this->assertSame([], $result['conflicts']);
    }

    // ---------- 手动绑定 ----------

    public function testBindingIsTerminalAndInvalidBindingFallsBackToNative(): void
    {
        $global = self::candidate(9);

        $native = DetailTemplateResolver::resolve([$global], self::ctx(), ['mode' => 'native']);
        $this->assertSame('binding_native', $native['reason']);
        $this->assertNull($native['template_id']);
        $this->assertSame('native', $native['source']);

        $pinned = DetailTemplateResolver::resolve([$global], self::ctx(), ['mode' => 'template', 'template_id' => 9]);
        $this->assertSame('binding_template', $pinned['reason']);
        $this->assertSame(9, $pinned['template_id']);

        // 失效引用：回退系统默认，绝不自动改选另一套
        $invalid = DetailTemplateResolver::resolve([$global], self::ctx(), ['mode' => 'template', 'template_id' => 404]);
        $this->assertSame('binding_invalid', $invalid['reason']);
        $this->assertNull($invalid['template_id']);
        $this->assertSame('native', $invalid['source']);

        // 类型/语言不符的手动绑定同样视为失效
        $wrongLang = self::candidate(9, ['lang' => 'ja']);
        $this->assertSame('binding_invalid', DetailTemplateResolver::resolve([$wrongLang], self::ctx(), ['mode' => 'template', 'template_id' => 9])['reason']);
    }

    // ---------- 匹配与具体度 ----------

    public function testSpecificityOrderItemThenCategoryThenAll(): void
    {
        $all = self::candidate(1);
        // 内容直属分类是 5（distance=0），未开「包含子级」时只有它能命中
        $category = self::candidate(2, ['include' => [['kind' => 'category', 'ids' => [5]]]]);
        $item = self::candidate(3, ['include' => [['kind' => 'item', 'ids' => [12]]]]);

        $result = DetailTemplateResolver::resolve([$all, $category, $item], self::ctx());
        $this->assertSame(3, $result['template_id']);
        $this->assertSame('specific_item', $result['reason']);
        $this->assertSame(3, $result['specificity']['level']);

        // 去掉 item 后分类胜出
        $result = DetailTemplateResolver::resolve([$all, $category], self::ctx());
        $this->assertSame(2, $result['template_id']);
        $this->assertSame('specific_category', $result['reason']);
        $this->assertSame(2, $result['specificity']['level']);

        // 只剩 all
        $this->assertSame('type_all', DetailTemplateResolver::resolve([$all], self::ctx())['reason']);
        $this->assertSame(1, DetailTemplateResolver::resolve([$all], self::ctx())['specificity']['level']);
    }

    public function testCategoryDistancePrefersNearestAncestor(): void
    {
        // 两者都开「包含子级」，才能真实比较距离：5=直属(0)、3=父级(1)
        $near = self::candidate(1, ['include' => [['kind' => 'category', 'ids' => [5], 'include_children' => true]]]);
        $far = self::candidate(2, ['include' => [['kind' => 'category', 'ids' => [3], 'include_children' => true]]]);

        // 先证明父级分类确实参与匹配（否则下面的比较是空过）
        $onlyFar = DetailTemplateResolver::resolve([$far], self::ctx());
        $this->assertSame(2, $onlyFar['template_id'], '开了子级后父级分类应命中');
        $this->assertSame(-1, $onlyFar['specificity']['detail'], '父级距离 1 → detail=-1');

        $result = DetailTemplateResolver::resolve([$far, $near], self::ctx());
        $this->assertSame(1, $result['template_id'], '距离更近的分类应胜出');
        $this->assertSame(0, $result['specificity']['detail']);

        // 顺序无关
        $this->assertSame($result, DetailTemplateResolver::resolve([$near, $far], self::ctx()));
    }

    public function testIncludeChildrenIsOptIn(): void
    {
        $parentOnly = self::candidate(1, ['include' => [['kind' => 'category', 'ids' => [3], 'include_children' => false]]]);
        $parentWithChildren = self::candidate(2, ['include' => [['kind' => 'category', 'ids' => [3], 'include_children' => true]]]);

        // 内容直属分类是 5，3 只是父级：未开子级不命中
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve([$parentOnly], self::ctx())['reason']);
        $this->assertSame(2, DetailTemplateResolver::resolve([$parentWithChildren], self::ctx())['template_id']);
    }

    public function testExcludeWinsOverIncludeRegardlessOfOrder(): void
    {
        $scoped = self::candidate(1, [
            'include' => [['kind' => 'item', 'ids' => [12]], ['kind' => 'all']],
            'exclude' => [['kind' => 'item', 'ids' => [12]]],
        ]);
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve([$scoped], self::ctx())['reason']);

        // 排除分类（含子级）同样生效
        $byCategory = self::candidate(2, [
            'include' => [['kind' => 'all']],
            'exclude' => [['kind' => 'category', 'ids' => [3], 'include_children' => true]],
        ]);
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve([$byCategory], self::ctx())['reason']);
    }

    public function testLanguageAndTypeNeverCrossMatch(): void
    {
        $otherLang = self::candidate(1, ['lang' => 'ja']);
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve([$otherLang], self::ctx())['reason']);

        // 类型词表不同：文章模板不会命中产品内容
        $article = ['id' => 1, 'type' => 'article-detail', 'status' => 1, 'scope' => DetailTemplateResolver::normalizeScope(
            ['content_type' => 'article', 'lang' => 'zh-CN', 'include' => [['kind' => 'all']]]
        )];
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve([$article], self::ctx())['reason']);
        // 而文章内容可以命中它
        $articleCtx = self::ctx(['content_type' => 'article', 'content_id' => 77, 'channel_type' => 'list']);
        $this->assertSame(1, DetailTemplateResolver::resolve([$article], $articleCtx)['template_id']);
    }

    public function testUnpublishedCandidateIsIgnored(): void
    {
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve([self::candidate(1, [], 'product-detail', 0)], self::ctx())['reason']);
    }

    // ---------- 冲突与确定性 ----------

    public function testEqualSpecificityAndPrioritySurfacesConflictWithDeterministicFallback(): void
    {
        $a = self::candidate(4);
        $b = self::candidate(7);

        $result = DetailTemplateResolver::resolve([$a, $b], self::ctx());
        $this->assertSame('conflicted', $result['reason']);
        $this->assertSame(7, $result['template_id'], '运行时兜底必须确定（取最大模板 ID），不得随机');
        $this->assertEqualsCanonicalizing([4, 7], array_column($result['conflicts'], 'template_id'));
        $this->assertSame('same_specificity_and_priority', $result['conflicts'][0]['dimension']);
    }

    public function testExplicitPriorityResolvesWithoutConflict(): void
    {
        $low = self::candidate(4);
        $high = self::candidate(7, ['priority' => 5]);
        $result = DetailTemplateResolver::resolve([$low, $high], self::ctx());
        $this->assertSame(7, $result['template_id']);
        $this->assertNotSame('conflicted', $result['reason']);
    }

    public function testResolutionIsDeterministicAndCandidateOrderIndependent(): void
    {
        $candidates = [
            self::candidate(1),
            self::candidate(2, ['include' => [['kind' => 'item', 'ids' => [12]]]]),
            self::candidate(3, ['include' => [['kind' => 'category', 'ids' => [5]]]]),
        ];
        $first = DetailTemplateResolver::resolve($candidates, self::ctx());
        $this->assertSame($first, DetailTemplateResolver::resolve($candidates, self::ctx()));

        $shuffled = [$candidates[2], $candidates[0], $candidates[1]];
        $this->assertSame($first, DetailTemplateResolver::resolve($shuffled, self::ctx()), '候选顺序不得影响结果');
    }

    public function testTemplateDeclaringNativeIsTerminalWhenItWins(): void
    {
        // v1 语义：先按具体度决出胜者，胜者 source=native 则终止（不得回落另一套自定义布局）
        $nativeAll = self::candidate(9, ['source' => 'native']);
        $customAll = self::candidate(7);
        $customItem = self::candidate(3, ['include' => [['kind' => 'item', 'ids' => [12]]]]);

        // 胜者是 native → 终止
        $this->assertSame('template_native', DetailTemplateResolver::resolve([$nativeAll, $customAll], self::ctx())['reason']);
        $this->assertNull(DetailTemplateResolver::resolve([$nativeAll], self::ctx())['template_id']);

        // 更具体的自定义规则胜出时照常生效（native 只在其胜出时终止）
        $winner = DetailTemplateResolver::resolve([$customItem, $nativeAll], self::ctx());
        $this->assertSame(3, $winner['template_id']);
        $this->assertSame('specific_item', $winner['reason']);
    }

    public function testNativeWinnerStillReportsTheTieAndWhoDecided(): void
    {
        $nativeAll = self::candidate(9, ['source' => 'native']);
        $customAll = self::candidate(4);
        $result = DetailTemplateResolver::resolve([$customAll, $nativeAll], self::ctx());

        $this->assertSame('template_native', $result['reason']);
        $this->assertNull($result['template_id'], '前台仍输出主题默认');
        $this->assertNull($result['template']);
        $this->assertSame(9, $result['decided_template_id'], 'ID 兜底选中的是声明 native 的模板');
        $this->assertEqualsCanonicalizing([4, 9], array_column($result['conflicts'], 'template_id'), '并列不因终止决策被隐藏');

        $this->assertSame(4, DetailTemplateResolver::resolve([$customAll], self::ctx())['decided_template_id']);
        $this->assertNull(DetailTemplateResolver::resolve([], self::ctx())['decided_template_id']);
        $pinned = DetailTemplateResolver::resolve([$customAll], self::ctx(), ['mode' => 'template', 'template_id' => 4]);
        $this->assertSame(4, $pinned['decided_template_id']);
    }

    public function testNoCandidatesDegradesToNativeNotSiteWide(): void
    {
        $result = DetailTemplateResolver::resolve([], self::ctx());
        $this->assertSame('no_candidate', $result['reason']);
        $this->assertSame('native', $result['source']);
        $this->assertNull($result['template_id']);
        $this->assertSame(2, $result['rule_version']);
    }

    // ---------- 与保存管线/导入契约的衔接 ----------

    public function testPipelineKeepsNewScopeKeyAndLeavesLegacyProductTemplateIntact(): void
    {
        $legacy = ['mode' => 'selected', 'ids' => ['12'], 'lang' => 'zh-CN'];
        $v2 = [
            'version' => 2,
            'content_type' => 'product',
            'lang' => 'zh-CN',
            'priority' => 3,
            'include' => [['kind' => 'category', 'ids' => [5], 'include_children' => true]],
            'exclude' => [['kind' => 'item', 'ids' => [99]]],
        ];

        $clean = BloxDocumentPipeline::normalizeDocSettings(['product_template' => $legacy, 'detail_template' => $v2]);
        // v1 键走它自己的归一化，语义与历史一致（未被新契约改写）
        $this->assertSame(['mode' => 'selected', 'ids' => [12], 'lang' => 'zh-CN'], $clean['product_template']);
        // v2 键必须存活，否则「保存即丢」
        $this->assertSame(2, $clean['detail_template']['version']);
        $this->assertSame('product', $clean['detail_template']['content_type']);
        $this->assertSame(3, $clean['detail_template']['priority']);
        $this->assertSame([99], $clean['detail_template']['exclude'][0]['ids']);
        $this->assertTrue($clean['detail_template']['include'][0]['include_children']);

        // 走真实保存管线（文档 → 归一化 JSON）后仍然保留
        $json = json_encode(['schema' => 1, 'settings' => ['detail_template' => $v2], 'sections' => []], JSON_THROW_ON_ERROR);
        $decoded = json_decode(BloxDocumentPipeline::process($json, 'product-template')['json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('product', $decoded['settings']['detail_template']['content_type']);
        $this->assertTrue($decoded['settings']['detail_template']['include'][0]['include_children']);
        $this->assertSame([99], $decoded['settings']['detail_template']['exclude'][0]['ids']);
        $this->assertArrayNotHasKey('legacy', $decoded['settings']['detail_template'], '内部标记不落盘');

        // 畸形条件经管线后是「不可用作用域」，不是全站
        $bad = BloxDocumentPipeline::normalizeDocSettings(['detail_template' => ['content_type' => 'nope', 'include' => [['kind' => 'all']]]]);
        $this->assertSame('', $bad['detail_template']['content_type']);
        $this->assertSame([], $bad['detail_template']['include']);
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve(
            [['id' => 1, 'type' => 'product-detail', 'status' => 1, 'scope' => $bad['detail_template']]],
            self::ctx()
        )['reason']);
    }
}
