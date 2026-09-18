<?php
/**
 * TASK-008：真实上下文诊断的**纯函数**部分（候选注入 / 前置条件 / 判定命名）。
 *
 * 不查库、不起服务；真实上下文与 resolve() 的接线由 e2e 与 HTTP 级验证覆盖。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailTemplateDiagnosisTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** @return array<string,mixed> */
    private static function candidate(int $id, array $overrides = [], string $type = 'product-detail', int $status = 1): array
    {
        return array_merge([
            'id' => $id,
            'type' => $type,
            'status' => $status,
            'lang' => 'zh-CN',
            'source' => 'custom',
            'priority' => 0,
            'scope' => DetailTemplateResolver::normalizeScope([
                'version' => 2,
                'content_type' => 'product',
                'lang' => 'zh-CN',
                'include' => [['kind' => 'all']],
            ]),
            'published_data' => '',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function draft(array $overrides = []): array
    {
        return array_merge([
            'version' => 2,
            'content_type' => 'product',
            'lang' => 'zh-CN',
            'source' => 'custom',
            'priority' => 0,
            'include' => [['kind' => 'item', 'ids' => [12], 'include_children' => false]],
            'exclude' => [],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function context(array $overrides = []): array
    {
        return array_merge([
            'content_type' => 'product',
            'content_id' => 12,
            'lang' => 'zh-CN',
            'channel_type' => 'product',
            'categories' => [['id' => 5, 'distance' => 0]],
            'ancestors' => [5],
        ], $overrides);
    }

    public function testDraftReplacesTheTemplatesOwnPublishedVersion(): void
    {
        $candidates = [self::candidate(7), self::candidate(9)];
        $result = DetailTemplateProvider::injectDraft($candidates, 'product', 7, self::draft());

        $ids = array_column($result, 'id');
        $this->assertSame([9, 7], $ids, '自身旧发布版本被移除，草稿以同一个 id 追加在末尾');
        $this->assertCount(1, array_filter($ids, static fn(int $id): bool => $id === 7), '同 id 只能出现一次');
    }

    public function testInjectedDraftLooksLikeAPublishedCandidate(): void
    {
        $result = DetailTemplateProvider::injectDraft([], 'product', 7, self::draft());
        $this->assertCount(1, $result);
        $draft = $result[0];
        $this->assertSame(7, $draft['id']);
        $this->assertSame('product-detail', $draft['type'], 'templateTypeFor 映射，不是 contents 词表');
        $this->assertSame(1, $draft['status'], '注入即"若现在发布"，否则 resolver 会直接跳过');
        $this->assertSame('zh-CN', $draft['lang']);
        $this->assertSame(0, $draft['priority']);
        $this->assertSame([['kind' => 'item', 'ids' => [12], 'include_children' => false]], $draft['scope']['include']);
    }

    public function testUnusableDraftIsNotInjected(): void
    {
        // 空 include＝"不应用"：不能以空条件参与后再装作命中
        $empty = DetailTemplateProvider::injectDraft([self::candidate(9)], 'product', 7, self::draft(['include' => []]));
        $this->assertSame([9], array_column($empty, 'id'));

        // 类型不符、id 非法同样不注入
        $wrongType = DetailTemplateProvider::injectDraft([], 'product', 7, self::draft(['content_type' => 'article']));
        $this->assertSame([], $wrongType);
        $this->assertSame([], DetailTemplateProvider::injectDraft([], 'product', 0, self::draft()));
        // 未知内容类型没有模板类型映射，也不注入
        $this->assertSame([], DetailTemplateProvider::injectDraft([], 'video', 7, self::draft()));
    }

    public function testPreconditionsUseResolverFacts(): void
    {
        $scope = DetailTemplateResolver::normalizeScope(self::draft());
        $this->assertSame('ok', DetailTemplateProvider::preconditionFor($scope, 'product', self::context()));

        // 跨语言：草稿语言与内容语言不同 → 不参与匹配（resolver 第一版不跨语言共享布局）
        $this->assertSame(
            'lang_mismatch',
            DetailTemplateProvider::preconditionFor($scope, 'product', self::context(['lang' => 'en']))
        );

        // 空 include / 类型不符 → scope_unusable
        $this->assertSame(
            'scope_unusable',
            DetailTemplateProvider::preconditionFor(DetailTemplateResolver::normalizeScope(self::draft(['include' => []])), 'product', self::context())
        );
        $this->assertSame(
            'scope_unusable',
            DetailTemplateProvider::preconditionFor($scope, 'article', self::context())
        );
    }

    public function testVerdictNamesTheResolverResultWithoutRejudging(): void
    {
        $templateId = 7;
        $this->assertSame('not_considered', DetailTemplateProvider::verdictFor('lang_mismatch', $templateId, ['template_id' => 7, 'reason' => 'specific_item']));
        $this->assertSame('no_match', DetailTemplateProvider::verdictFor('ok', $templateId, ['template_id' => null, 'reason' => DetailTemplateResolver::REASON_NO_CANDIDATE]));
        $this->assertSame('won', DetailTemplateProvider::verdictFor('ok', $templateId, ['template_id' => 7, 'reason' => DetailTemplateResolver::REASON_SPECIFIC_ITEM]));
        $this->assertSame('conflicted', DetailTemplateProvider::verdictFor('ok', $templateId, ['template_id' => 7, 'reason' => DetailTemplateResolver::REASON_CONFLICTED]));
        $this->assertSame('lost', DetailTemplateProvider::verdictFor('ok', $templateId, ['template_id' => 9, 'reason' => DetailTemplateResolver::REASON_SPECIFIC_ITEM]));
    }

    public function testVerdictNamesNativeDecisionsAndTiesAmongOthers(): void
    {
        $native = ['template_id' => null, 'decided_template_id' => 7, 'reason' => DetailTemplateResolver::REASON_TEMPLATE_NATIVE, 'conflicts' => []];
        $this->assertSame('native', DetailTemplateProvider::verdictFor('ok', 7, $native), '本模板决定走主题默认，不是"没有模板命中"');
        $this->assertSame('lost', DetailTemplateProvider::verdictFor('ok', 3, $native), '别的模板决定走默认，本模板不生效');

        $othersTied = [
            'template_id' => 9, 'decided_template_id' => 9, 'reason' => DetailTemplateResolver::REASON_CONFLICTED,
            'conflicts' => [['template_id' => 9], ['template_id' => 4]],
        ];
        $this->assertSame('lost', DetailTemplateProvider::verdictFor('ok', 7, $othersTied), '并列发生在其它模板之间');
        $this->assertSame('conflicted', DetailTemplateProvider::verdictFor('ok', 4, $othersTied), 'ID 兜底落败的并列成员仍是冲突');
        $this->assertSame('conflicted', DetailTemplateProvider::verdictFor('ok', 9, $othersTied), 'ID 兜底胜出也不代表无冲突');
    }

    public function testDraftMatchExplainsExclusionWithTheSameResolver(): void
    {
        $ctx = self::context();
        $this->assertSame('matched', DetailTemplateProvider::draftMatchFor('product', 7, self::draft(), $ctx, 'ok'));

        $excluded = self::draft(['exclude' => [['kind' => 'category', 'ids' => [5], 'include_children' => false]]]);
        $this->assertSame('excluded', DetailTemplateProvider::draftMatchFor('product', 7, $excluded, $ctx, 'ok'));

        $elsewhere = self::draft(['include' => [['kind' => 'item', 'ids' => [99], 'include_children' => false]]]);
        $this->assertSame('not_included', DetailTemplateProvider::draftMatchFor('product', 7, $elsewhere, $ctx, 'ok'));

        $ancestorOnly = self::draft(['include' => [['kind' => 'category', 'ids' => [5], 'include_children' => false]]]);
        $this->assertSame('not_included', DetailTemplateProvider::draftMatchFor(
            'product', 7, $ancestorOnly, self::context(['categories' => [['id' => 8, 'distance' => 0], ['id' => 5, 'distance' => 1]]]), 'ok'
        ), '未开"含子级"时祖先分类不命中');

        $native = self::draft(['source' => 'native']);
        $this->assertSame('matched', DetailTemplateProvider::draftMatchFor('product', 7, $native, $ctx, 'ok'), 'native 草稿命中也算命中');
        $this->assertSame('not_considered', DetailTemplateProvider::draftMatchFor('product', 7, self::draft(), $ctx, 'lang_mismatch'));
    }

    public function testRealTieIsDetectedThroughTheSameResolver(): void
    {
        // 两条规则完全相同、同级同优先级：resolve() 会报 conflicted（不新造算法，走真实判定）
        $draft = self::draft();
        $published = self::candidate(9, ['scope' => DetailTemplateResolver::normalizeScope($draft)]);
        $candidates = DetailTemplateProvider::injectDraft([$published], 'product', 7, $draft);
        $result = DetailTemplateResolver::resolve($candidates, DetailTemplateResolver::normalizeContext(self::context()));

        $this->assertSame(DetailTemplateResolver::REASON_CONFLICTED, $result['reason'], '同级同优先级并列');
        $this->assertNotEmpty($result['conflicts'], '并列清单非空');
        $conflictIds = array_column($result['conflicts'], 'template_id');
        sort($conflictIds);
        $this->assertSame([7, 9], $conflictIds, '并列双方都列出（含注入的草稿）');
        $this->assertSame('conflicted', DetailTemplateProvider::verdictFor('ok', 7, $result));
        $this->assertSame('lost', DetailTemplateProvider::verdictFor('ok', 3, $result));
    }

    public function testOwnOldPublishedVersionNeverTiesWithItself(): void
    {
        // 同一份规则：若不移除自身旧发布版本，7 会出现两次并自造冲突
        $published = self::candidate(7);
        $withSelf = DetailTemplateResolver::resolve([$published], DetailTemplateResolver::normalizeContext(self::context()));
        $this->assertSame(7, $withSelf['template_id']);

        $candidates = DetailTemplateProvider::injectDraft([$published], 'product', 7, self::draft());
        $result = DetailTemplateResolver::resolve($candidates, DetailTemplateResolver::normalizeContext(self::context()));
        $this->assertSame(7, $result['template_id']);
        $this->assertSame([], $result['conflicts'], '排除自身旧版本后不与自身并列');
    }
}
