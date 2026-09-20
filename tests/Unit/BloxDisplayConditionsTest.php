<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxDisplayConditionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function setUp(): void
    {
        BloxDisplayConditions::resetForTests();
    }

    /** v1.27 六个新维度：language / device / datetime / param / field（role 无实体，见类注释）。 */
    public function testV127ConditionTypesEvaluateDeterministically(): void
    {
        $ctx = static fn (array $overrides): array => array_merge([
            'logged_in' => false, 'date' => '2026-09-20', 'datetime' => '2026-09-20 12:00',
            'channel_id' => 0, 'url' => '/', 'lang' => 'zh-CN', 'device' => 'desktop',
            'params' => [], 'fields' => [],
        ], $overrides);

        // language is / is_not
        $langRule = [['rules' => [['type' => 'language', 'operator' => 'is', 'value' => 'ja']]]];
        self::assertTrue(BloxDisplayConditions::matches($langRule, $ctx(['lang' => 'ja'])));
        self::assertFalse(BloxDisplayConditions::matches($langRule, $ctx(['lang' => 'zh-CN'])));

        // device：mobile/desktop 二分（与 HtmlCache 缓存键 isMobile 同源）
        $deviceRule = [['rules' => [['type' => 'device', 'operator' => 'is', 'value' => 'mobile']]]];
        self::assertTrue(BloxDisplayConditions::matches($deviceRule, $ctx(['device' => 'mobile'])));
        self::assertFalse(BloxDisplayConditions::matches($deviceRule, $ctx([])));

        // datetime：分钟粒度 before/after
        $timeRule = [['rules' => [['type' => 'datetime', 'operator' => 'after', 'value' => '2026-09-20 11:30']]]];
        self::assertTrue(BloxDisplayConditions::matches($timeRule, $ctx([])));
        self::assertFalse(BloxDisplayConditions::matches($timeRule, $ctx(['datetime' => '2026-09-20 11:00'])));

        // param：equals / exists / not_exists（值缺失时 not_equals 视为不同）
        $paramEq = [['rules' => [['type' => 'param', 'operator' => 'equals', 'value' => 'vip', 'name' => 'from']]]];
        self::assertTrue(BloxDisplayConditions::matches($paramEq, $ctx(['params' => ['from' => 'vip']])));
        self::assertFalse(BloxDisplayConditions::matches($paramEq, $ctx(['params' => ['from' => 'ad']])));
        self::assertFalse(BloxDisplayConditions::matches($paramEq, $ctx([])));
        $paramMissing = [['rules' => [['type' => 'param', 'operator' => 'not_exists', 'value' => '', 'name' => 'preview']]]];
        self::assertTrue(BloxDisplayConditions::matches($paramMissing, $ctx([])));
        self::assertFalse(BloxDisplayConditions::matches($paramMissing, $ctx(['params' => ['preview' => '1']])));

        // field：显式注入 fields 求值；无值时 equals fail-closed、empty 视为空
        $fieldEq = [['rules' => [['type' => 'field', 'operator' => 'equals', 'value' => 'red', 'name' => 'color']]]];
        self::assertTrue(BloxDisplayConditions::matches($fieldEq, $ctx(['fields' => ['color' => 'red']])));
        self::assertFalse(BloxDisplayConditions::matches($fieldEq, $ctx(['fields' => []])));
        $fieldEmpty = [['rules' => [['type' => 'field', 'operator' => 'empty', 'value' => '', 'name' => 'color']]]];
        self::assertTrue(BloxDisplayConditions::matches($fieldEmpty, $ctx(['fields' => []])));
        self::assertFalse(BloxDisplayConditions::matches($fieldEmpty, $ctx(['fields' => ['color' => 'red']])));
    }

    /** v1.27 新键的非法形态照旧 fail-closed（整组不可用 → 不显示）。 */
    public function testV127MalformedRulesFailClosed(): void
    {
        foreach ([
            ['type' => 'datetime', 'operator' => 'after', 'value' => '2026-09-20'],          // 缺分钟
            ['type' => 'datetime', 'operator' => 'on', 'value' => '2026-09-20 11:30'],       // 非法算子
            ['type' => 'language', 'operator' => 'is', 'value' => 'not a lang'],
            ['type' => 'device', 'operator' => 'is', 'value' => 'tablet'],                   // 刻意不支持
            ['type' => 'param', 'operator' => 'equals', 'value' => 'x', 'name' => 'bad name'],
            ['type' => 'param', 'operator' => 'equals', 'value' => '', 'name' => 'ok'],      // 缺值
            ['type' => 'field', 'operator' => 'equals', 'value' => 'x', 'name' => '1bad'],
        ] as $rule) {
            self::assertFalse(BloxDisplayConditions::matches([['rules' => [$rule]]], [
                'lang' => 'zh-CN',
            ]), json_encode($rule));
        }
    }

    /** v1.27 缓存安全分级：只有 datetime 触发禁整页缓存，且与求值结果无关。 */
    public function testCacheSafetyGradingReportsUnsafeEvaluations(): void
    {
        $safe = [['rules' => [
            ['type' => 'language', 'operator' => 'is', 'value' => 'zh-CN'],
            ['type' => 'device', 'operator' => 'is', 'value' => 'desktop'],
            ['type' => 'param', 'operator' => 'exists', 'value' => '', 'name' => 'page'],
        ]]];
        self::assertFalse(BloxDisplayConditions::cacheUnsafe($safe));
        BloxDisplayConditions::matches($safe, ['lang' => 'zh-CN', 'device' => 'desktop', 'params' => []]);
        self::assertFalse(BloxDisplayConditions::pageCacheMustSkip());

        $unsafe = [['rules' => [['type' => 'datetime', 'operator' => 'before', 'value' => '2026-01-01 00:00']]]];
        self::assertTrue(BloxDisplayConditions::cacheUnsafe($unsafe));
        // 求值结果为 false（条件不命中）也一样上报——另一分支被冻结同样是错的
        self::assertFalse(BloxDisplayConditions::matches($unsafe, ['datetime' => '2026-09-20 12:00']));
        self::assertTrue(BloxDisplayConditions::pageCacheMustSkip());

        BloxDisplayConditions::resetForTests();
        self::assertFalse(BloxDisplayConditions::pageCacheMustSkip());
    }

    public function testGroupsUseOrAndRulesInsideAGroupUseAnd(): void
    {
        $conditions = [
            ['rules' => [
                ['type' => 'login', 'operator' => 'is', 'value' => 'logged_in'],
                ['type' => 'channel', 'operator' => 'is', 'value' => 7],
            ]],
            ['rules' => [
                ['type' => 'url', 'operator' => 'starts_with', 'value' => '/campaign'],
            ]],
        ];

        self::assertTrue(BloxDisplayConditions::matches($conditions, [
            'logged_in' => true, 'channel_id' => 7, 'date' => '2026-08-15', 'url' => '/products',
        ]));
        self::assertFalse(BloxDisplayConditions::matches($conditions, [
            'logged_in' => true, 'channel_id' => 8, 'date' => '2026-08-15', 'url' => '/products',
        ]));
        self::assertTrue(BloxDisplayConditions::matches($conditions, [
            'logged_in' => false, 'channel_id' => 0, 'date' => '2026-08-15', 'url' => '/campaign/summer',
        ]));
    }

    public function testDateUrlAndNegativeChannelOperatorsAreDeterministic(): void
    {
        self::assertTrue(BloxDisplayConditions::matches([['rules' => [
            ['type' => 'date', 'operator' => 'after', 'value' => '2026-08-01'],
            ['type' => 'channel', 'operator' => 'is_not', 'value' => 9],
            ['type' => 'url', 'operator' => 'contains', 'value' => 'source=ad'],
        ]]], [
            'logged_in' => false, 'channel_id' => 7, 'date' => '2026-08-15', 'url' => '/offer?source=ad',
        ]));
    }

    public function testMalformedOrUnknownRulesFailClosed(): void
    {
        self::assertFalse(BloxDisplayConditions::matches([['rules' => []]], []));
        self::assertFalse(BloxDisplayConditions::matches([['rules' => [[
            'type' => 'role', 'operator' => 'is', 'value' => 'admin',
        ]]]], []));
        self::assertFalse(BloxDisplayConditions::matches([['rules' => [[
            'type' => 'date', 'operator' => 'on', 'value' => '2026-02-31',
        ]]]], []));
        self::assertTrue(BloxDisplayConditions::matches([], []));
    }

    public function testServerRejectsInvalidAndUnlicensedConditionDocuments(): void
    {
        $valid = $this->sections([
            ['rules' => [['type' => 'login', 'operator' => 'is', 'value' => 'logged_out']]],
        ]);
        BloxDisplayConditions::assertSectionsAllowed($valid, true);
        $this->addToAssertionCount(1);

        try {
            BloxDisplayConditions::assertSectionsAllowed($valid, false);
            self::fail('Display conditions must be license-gated on the server.');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_display_conditions_license_required'), $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        BloxDisplayConditions::assertSectionsAllowed($this->sections([
            ['rules' => [['type' => 'url', 'operator' => 'eval', 'value' => 'x']]],
        ]), true);
    }

    public function testFrontendOmitsNonMatchingNodesAndCanvasKeepsMarkers(): void
    {
        $conditions = [[
            'rules' => [['type' => 'login', 'operator' => 'is', 'value' => 'logged_in']],
        ]];
        $json = json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'type' => 'heading',
                'data' => ['text' => 'Members only', '_conditions' => $conditions],
            ]]]],
        ]], JSON_THROW_ON_ERROR);

        unset($_SESSION['member_id'], $_SESSION['admin_id']);
        self::assertStringNotContainsString('Members only', BlockRenderer::render($json));

        BlockRenderer::$editChannelId = 1;
        $_SESSION['admin_id'] = 1;
        try {
            $canvas = BlockRenderer::render($json);
        } finally {
            BlockRenderer::$editChannelId = 0;
            unset($_SESSION['admin_id']);
        }
        self::assertStringContainsString('Members only', $canvas);
        self::assertStringContainsString('data-yk-conditions="1/1"', $canvas);
    }

    /** @return array<int,array<string,mixed>> */
    private function sections(array $conditions): array
    {
        return [[
            'type' => 'section',
            'settings' => ['_conditions' => $conditions],
            'columns' => [['elements' => [[
                'type' => 'heading', 'data' => ['text' => 'Heading'],
            ]]]],
        ]];
    }
}
