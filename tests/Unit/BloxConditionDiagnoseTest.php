<?php
/**
 * 元素显示条件的求值诊断（E10）。
 *
 * 详情模板早有诊断面板，元素条件此前只有"几组几条"的角标——作者看不出规则此刻
 * 是否命中。诊断补上这一层，但必须守住两条：与 matches() 判定一致（不能出现
 * "诊断说命中、前台却不显示"），以及不回显字段内容。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxDisplayConditions;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxConditionDiagnoseTest extends TestCase
{
    protected function setUp(): void
    {
        BloxDisplayConditions::resetForTests();
    }

    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'logged_in' => false, 'date' => '2026-09-22', 'datetime' => '2026-09-22 10:00',
            'channel_id' => 0, 'url' => '/', 'lang' => 'zh-CN', 'device' => 'desktop',
            'params' => [], 'fields' => [],
        ], $overrides);
    }

    /** 诊断结论必须与 matches() 完全一致，否则作者会被误导。 */
    public function testVerdictAlwaysAgreesWithMatches(): void
    {
        $cases = [
            [['rules' => [['type' => 'language', 'operator' => 'is', 'value' => 'zh-CN']]]],
            [['rules' => [['type' => 'language', 'operator' => 'is', 'value' => 'ja']]]],
            [['rules' => [['type' => 'device', 'operator' => 'is', 'value' => 'desktop'],
                          ['type' => 'language', 'operator' => 'is', 'value' => 'ja']]]],
            [['rules' => [['type' => 'device', 'operator' => 'is', 'value' => 'mobile']]],
             ['rules' => [['type' => 'language', 'operator' => 'is', 'value' => 'zh-CN']]]],
        ];
        foreach ($cases as $index => $conditions) {
            $context = $this->ctx();
            $report = BloxDisplayConditions::diagnose($conditions, $context);
            self::assertIsArray($report, "用例 {$index}");
            self::assertSame(
                BloxDisplayConditions::matches($conditions, $context),
                $report['matched'],
                "用例 {$index}：诊断结论与渲染判定必须一致"
            );
        }
    }

    /** 逐条给出命中与否，组内 AND、组间 OR 的语义要看得出来。 */
    public function testPerRuleAndPerGroupResultsAreReported(): void
    {
        $conditions = [
            ['rules' => [
                ['type' => 'language', 'operator' => 'is', 'value' => 'zh-CN'],
                ['type' => 'device', 'operator' => 'is', 'value' => 'mobile'],
            ]],
            ['rules' => [['type' => 'device', 'operator' => 'is', 'value' => 'desktop']]],
        ];
        $report = BloxDisplayConditions::diagnose($conditions, $this->ctx());
        self::assertIsArray($report);

        // 第一组：语言命中、设备没命中 → 组不成立（AND）
        self::assertTrue($report['groups'][0]['rules'][0]['matched']);
        self::assertFalse($report['groups'][0]['rules'][1]['matched']);
        self::assertFalse($report['groups'][0]['matched']);

        // 第二组成立 → 整体命中（OR）
        self::assertTrue($report['groups'][1]['matched']);
        self::assertTrue($report['matched']);
    }

    /** 空条件＝始终显示；非法条件与 matches() 一样 fail-closed。 */
    public function testEmptyAndMalformedInputsFollowTheRenderContract(): void
    {
        $empty = BloxDisplayConditions::diagnose(null, $this->ctx());
        self::assertSame(['matched' => true, 'groups' => []], $empty);

        $malformed = [['rules' => [['type' => 'nope', 'operator' => 'is', 'value' => 'x']]]];
        self::assertNull(BloxDisplayConditions::diagnose($malformed, $this->ctx()),
            '非法条件返回 null，与 matches() 的 fail-closed 同源');
        self::assertFalse(BloxDisplayConditions::matches($malformed, $this->ctx()));
    }

    /** 诊断只回报命中与否，不带出字段内容。 */
    public function testReportCarriesNoFieldValues(): void
    {
        $conditions = [['rules' => [
            ['type' => 'field', 'operator' => 'equals', 'value' => '机密值', 'name' => 'secret'],
        ]]];
        $report = BloxDisplayConditions::diagnose($conditions, $this->ctx(['fields' => ['secret' => '机密值']]));
        self::assertIsArray($report);
        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('机密值', $encoded, '诊断结果不得回显字段内容');
        self::assertArrayHasKey('matched', $report['groups'][0]['rules'][0]);
    }

    /** 诊断不得影响缓存安全上报——它是编辑期辅助，不该让前台页面丢缓存。 */
    public function testDiagnoseDoesNotFlagThePageAsCacheUnsafe(): void
    {
        $unsafe = [['rules' => [['type' => 'datetime', 'operator' => 'before', 'value' => '2030-01-01 00:00']]]];
        BloxDisplayConditions::diagnose($unsafe, $this->ctx());
        self::assertFalse(BloxDisplayConditions::pageCacheMustSkip(),
            '诊断是编辑期调用，不该把当前页标为不可缓存');
    }
}
