<?php
/**
 * TASK-005 B：作用域只读摘要的纯函数契约。
 *
 * 只描述**存储的规则**：纳入/排除分开、逐条保留 include_children、缺失名称回退 ID；
 * 不计算命中数量、不改写任何字段。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailScopeSummaryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testSplitsIncludeAndExcludeAndKeepsPerRuleChildrenFlag(): void
    {
        $scope = [
            'include' => [
                ['kind' => 'category', 'ids' => [5], 'include_children' => true],
                ['kind' => 'category', 'ids' => [7], 'include_children' => false],
                ['kind' => 'item', 'ids' => [1], 'include_children' => false],
            ],
            'exclude' => [['kind' => 'category', 'ids' => [9], 'include_children' => true]],
        ];

        $rules = DetailScopeSummary::categoryRules($scope);
        $this->assertCount(2, $rules['include'], '逐条保留纳入分类规则');
        $this->assertCount(1, $rules['exclude'], '排除与纳入分开');
        $this->assertTrue($rules['include'][0]['include_children']);
        $this->assertFalse($rules['include'][1]['include_children'], '不能拿任意一条推断其它规则');
        $this->assertSame([9], $rules['exclude'][0]['ids']);
        $this->assertTrue(DetailScopeSummary::hasCategory($scope));
    }

    public function testNoCategoryRulesMeansNoPresentation(): void
    {
        $this->assertFalse(DetailScopeSummary::hasCategory(['include' => [['kind' => 'all']], 'exclude' => []]));
        $this->assertFalse(DetailScopeSummary::hasCategory([]));
        $this->assertSame(['include' => [], 'exclude' => []], DetailScopeSummary::categoryRules(['include' => 'bad']));
    }

    public function testLabelsFallBackToIdAndNeverInventNames(): void
    {
        $rules = DetailScopeSummary::categoryRules([
            'include' => [['kind' => 'category', 'ids' => [5, 6], 'include_children' => false]],
        ]);
        $labelled = DetailScopeSummary::labelled($rules['include'], [5 => '硬件', 6 => '  ']);
        $this->assertSame([['labels' => ['硬件', 'ID:6'], 'include_children' => false]], $labelled, '空名回退 ID');
    }

    public function testSummaryIsReadOnly(): void
    {
        $scope = ['include' => [['kind' => 'category', 'ids' => [5], 'include_children' => true]], 'exclude' => []];
        $before = json_encode($scope, JSON_THROW_ON_ERROR);
        DetailScopeSummary::categoryRules($scope);
        DetailScopeSummary::labelled(DetailScopeSummary::categoryRules($scope)['include'], []);
        DetailScopeSummary::hasCategory($scope);
        $this->assertSame($before, json_encode($scope, JSON_THROW_ON_ERROR), '摘要函数不得改写传入结构');
    }
}
