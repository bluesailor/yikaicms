<?php
/**
 * TASK-006 第一批：完整条件提交的严格校验 + 与旧简化投影互斥。
 *
 * 这些用例只针对纯函数 DetailConditionInput（不触库、不起服务）。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailConditionInputTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** @return array<string,mixed> */
    private static function languages(): array
    {
        return ['zh-CN' => '中文', 'en' => 'English'];
    }

    private static function valid(): array
    {
        return [
            'version' => 2,
            'content_type' => 'product',
            'lang' => 'zh-CN',
            'source' => 'custom',
            'priority' => 3,
            'include' => [
                ['kind' => 'category', 'ids' => [5], 'include_children' => true],
                ['kind' => 'item', 'ids' => ['7', 9], 'include_children' => false],
            ],
            'exclude' => [['kind' => 'category', 'ids' => [12], 'include_children' => false]],
        ];
    }

    public function testAcceptsMixedRulesAndKeepsResolverShape(): void
    {
        $result = DetailConditionInput::validate(self::valid(), 'product', self::languages());
        $this->assertTrue($result['ok'], $result['error']);
        $scope = $result['scope'];
        $this->assertSame(2, $scope['version']);
        $this->assertSame('product', $scope['content_type']);
        $this->assertSame(3, $scope['priority']);
        $this->assertSame([
            ['kind' => 'category', 'ids' => [5], 'include_children' => true],
            ['kind' => 'item', 'ids' => [7, 9], 'include_children' => false],
        ], $scope['include'], 'item 的子级标志归一为 false，字符串 id 转整数');
        $this->assertSame([['kind' => 'category', 'ids' => [12], 'include_children' => false]], $scope['exclude']);
        $this->assertArrayNotHasKey('legacy', $scope, '落库形状不含内部标记');
    }

    public function testEmptyIncludeIsLegalAndMeansNotApplied(): void
    {
        $input = self::valid();
        $input['include'] = [];
        $result = DetailConditionInput::validate($input, 'product', self::languages());
        $this->assertTrue($result['ok'], '空 include 是合法值（不应用），不是非法结构');
        $this->assertSame([], $result['scope']['include']);
    }

    public function testRejectsInsteadOfSilentlyDropping(): void
    {
        // 未知 kind：归一化会静默丢弃，完整提交必须报错
        $input = self::valid();
        $input['include'] = [['kind' => 'future-kind', 'ids' => [1], 'include_children' => false]];
        $this->assertFalse(DetailConditionInput::validate($input, 'product', self::languages())['ok']);

        // 缺 ids 的 item 规则同样报错（而不是被丢掉后报保存成功）
        $input = self::valid();
        $input['include'] = [['kind' => 'item']];
        $this->assertFalse(DetailConditionInput::validate($input, 'product', self::languages())['ok']);

        // 非法 id、非法子级标志
        foreach ([['kind' => 'item', 'ids' => ['abc']], ['kind' => 'category', 'ids' => [1], 'include_children' => 'yes']] as $badRule) {
            $input = self::valid();
            $input['include'] = [$badRule];
            $this->assertFalse(DetailConditionInput::validate($input, 'product', self::languages())['ok']);
        }
    }

    public function testExcludeFollowsResolverAndRejectsAll(): void
    {
        $input = self::valid();
        $input['exclude'] = [['kind' => 'all', 'ids' => [], 'include_children' => false]];
        $result = DetailConditionInput::validate($input, 'product', self::languages());
        $this->assertFalse($result['ok'], 'exclude 不支持 all（与 resolver 一致）');
        $this->assertStringContainsString('exclude_all_not_allowed', $result['error']);
    }

    public function testTemplateTypeAndLanguageAndPriorityAreChecked(): void
    {
        // 真实模板类型与提交类型不一致 → 拒绝（产品模板不接受 article 条件）
        $this->assertSame('content_type_mismatch', DetailConditionInput::validate(self::valid(), 'article', self::languages())['error']);

        $input = self::valid();
        $input['content_type'] = 'video';
        $this->assertSame('bad_content_type', DetailConditionInput::validate($input, 'product', self::languages())['error']);

        $input = self::valid();
        $input['lang'] = 'fr';
        $this->assertSame('bad_lang', DetailConditionInput::validate($input, 'product', self::languages())['error']);

        $input = self::valid();
        $input['priority'] = 'high';
        $this->assertSame('bad_priority', DetailConditionInput::validate($input, 'product', self::languages())['error']);

        $input = self::valid();
        $input['version'] = 1;
        $this->assertSame('bad_version', DetailConditionInput::validate($input, 'product', self::languages())['error']);

        $this->assertSame('not_object', DetailConditionInput::validate('nope', 'product', self::languages())['error']);
        $this->assertSame('bad_template_type', DetailConditionInput::validate(self::valid(), 'popup', self::languages())['error']);
    }

    public function testLimitsMatchResolver(): void
    {
        $input = self::valid();
        $input['include'] = array_fill(0, DetailTemplateResolver::MAX_RULES + 1, ['kind' => 'item', 'ids' => [1]]);
        $this->assertStringContainsString('too_many_rules', DetailConditionInput::validate($input, 'product', self::languages())['error']);

        $input = self::valid();
        $input['include'] = [['kind' => 'item', 'ids' => array_fill(0, DetailTemplateResolver::MAX_IDS_PER_RULE + 1, 1)]];
        $this->assertStringContainsString('too_many_ids', DetailConditionInput::validate($input, 'product', self::languages())['error']);

        $input = self::valid();
        $input['priority'] = DetailTemplateResolver::MAX_PRIORITY + 50;
        $this->assertSame(DetailTemplateResolver::MAX_PRIORITY, DetailConditionInput::validate($input, 'product', self::languages())['scope']['priority']);
    }

    public function testValidationIsPure(): void
    {
        $input = self::valid();
        $before = json_encode($input, JSON_THROW_ON_ERROR);
        DetailConditionInput::validate($input, 'product', self::languages());
        $this->assertSame($before, json_encode($input, JSON_THROW_ON_ERROR), '校验不得改写传入结构');
    }
}
