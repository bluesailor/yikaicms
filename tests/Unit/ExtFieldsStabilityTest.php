<?php
/**
 * 高级字段稳定化（2.0.5 §5.3a）：重复器边界（空 / 1 行 / 100 行 / 上限 / 删除 / 排序 / 坏数据）、
 * 关联（顺序、去重、上限、译文页面换成同语言版本）、条件逻辑四种运算（含多选）、
 * 多语言复制、隐藏字段与「全不勾」的保存语义、整站模板携带的字段值归属。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use ExtFields;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class ExtFieldsStabilityTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, owner_id INTEGER, meta_key TEXT, meta_value TEXT, created_at INTEGER, updated_at INTEGER)',
            'CREATE UNIQUE INDEX uk_owner_key_metas ON metas (owner_type, owner_id, meta_key)',
            "CREATE TABLE extfields (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, field_key TEXT, field_name TEXT, field_type TEXT DEFAULT 'text',
                options TEXT, placeholder TEXT DEFAULT '', help_text TEXT DEFAULT '', is_required INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1, created_at INTEGER DEFAULT 0)",
            "CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, status INTEGER DEFAULT 1, lang TEXT DEFAULT 'zh-CN',
                translation_group_id INTEGER DEFAULT 0, deleted_at INTEGER DEFAULT NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../fixtures/ext-fields-stubs.php';
        require_once ROOT_PATH . '/includes/ExtFields.php';
        ExtFields::flushCache();
    }

    /** @param array<string,mixed> $config @return array<string,mixed> 字段定义（含 config） */
    private function define(string $owner, string $key, string $type, array $config = [], array $extra = []): array
    {
        $id = (int) $this->insertRow('extfields', $extra + ['owner_type' => $owner, 'field_key' => $key, 'field_name' => ucfirst($key), 'field_type' => $type]);
        ExtFields::saveConfig($id, $type, $config);
        ExtFields::flushCache();
        return (array) ExtFields::field($owner, $key);
    }

    private const SUBS = ['sub_fields' => [
        ['key' => 'model', 'name' => 'Model', 'type' => 'text'],
        ['key' => 'load', 'name' => 'Load', 'type' => 'number'],
        ['key' => 'stock', 'name' => 'Stock', 'type' => 'switch'],
    ]];

    public function testRepeaterBoundaries(): void
    {
        $field = $this->define('product', 'specs', 'repeater', self::SUBS);
        $rows = static fn (string $stored): array => (array) ExtFields::decode('repeater', $stored);

        // 空：空串、空数组、坏 JSON、非数组行、只有开关默认 0 的行 → 存空
        foreach (['', '[]', '{not json', '"x"', '[1,"a",null]', '[{"stock":"0"},{"model":"  "}]'] as $empty) {
            self::assertSame('', ExtFields::sanitize($field, $empty), $empty);
        }
        // 1 行；数字子字段收紧；未知子键丢弃
        self::assertSame([['model' => 'SE7', 'load' => '35', 'stock' => '1']],
            $rows(ExtFields::sanitize($field, [['model' => ' SE7 ', 'load' => '35.0', 'stock' => 'on', 'evil' => '<script>']])));
        // 100 行：全部保留、顺序不变
        $hundred = array_map(static fn (int $i): array => ['model' => 'M' . $i], range(1, 100));
        $kept = $rows(ExtFields::sanitize($field, json_encode($hundred)));
        self::assertCount(100, $kept);
        self::assertSame(['M1', 'M50', 'M100'], [$kept[0]['model'], $kept[49]['model'], $kept[99]['model']]);
        // 超过 MAX_ROWS 截断
        $many = array_map(static fn (int $i): array => ['model' => 'M' . $i], range(1, ExtFields::MAX_ROWS + 50));
        self::assertCount(ExtFields::MAX_ROWS, $rows(ExtFields::sanitize($field, $many)));
        // 配置了上限：只留前 N 行
        $limited = $this->define('product', 'top3', 'repeater', self::SUBS + ['max' => 3]);
        self::assertSame(['M1', 'M2', 'M3'], array_column($rows(ExtFields::sanitize($limited, $hundred)), 'model'));
        // 删除 / 排序：提交什么顺序存什么顺序，少提交的行即删除
        setMeta('product', 1, 'specs', ExtFields::sanitize($field, [['model' => 'A'], ['model' => 'B'], ['model' => 'C']]));
        ExtFields::save('product', 1, ['specs' => [['model' => 'C'], ['model' => 'A']]]);
        self::assertSame(['C', 'A'], array_column(ExtFields::rows('product', 1, 'specs'), 'model'));
        // 整组删光
        ExtFields::save('product', 1, ['specs' => []]);
        self::assertSame([], ExtFields::rows('product', 1, 'specs'));
        self::assertSame('', ExtFields::raw('product', 1, 'specs'));
    }

    public function testRelationshipOrderLimitAndLanguageSiblings(): void
    {
        $field = $this->define('product', 'related', 'relationship', ['target' => 'product', 'max' => 3]);
        self::assertSame('5,2,9', ExtFields::sanitize($field, ['5', 2, '5', 0, -1, 'x', 9, 11]), '保持选择顺序、去重、丢掉非法 id、按上限截断');
        self::assertSame('', ExtFields::sanitize($field, ''));

        // 翻译组：中文 1/2/3，英文 4（=1）、5（=3）；2 没有英文版
        foreach ([['A', 'zh-CN', 0], ['B', 'zh-CN', 0], ['C', 'zh-CN', 0]] as [$title, $lang, $group]) {
            $this->insertRow('products', ['title' => $title, 'lang' => $lang, 'translation_group_id' => $group]);
        }
        $this->insertRow('products', ['title' => 'A en', 'lang' => 'en', 'translation_group_id' => 1]);
        $this->insertRow('products', ['title' => 'C en', 'lang' => 'en', 'translation_group_id' => 3]);
        // 英文页面上，从中文复制来的「3,1,2」→ 英文版本，顺序不变，没有英文版的去掉
        self::assertSame([5, 4], ExtFields::localizeIds('products', [3, 1, 2], 'en'));
        self::assertSame([3, 1, 2], ExtFields::localizeIds('products', [3, 1, 2], 'zh-CN'), '本语言的原样保留');
        self::assertSame([4, 5], ExtFields::localizeIds('products', [4, 1, 5, 99], 'en'), '已是英文的保留，重复的合并，不存在的去掉');
        self::assertSame([1, 3], ExtFields::localizeIds('products', [4, 5], 'zh-CN'), '反方向同样成立');
        self::assertSame([], ExtFields::localizeIds('products', [], 'en'));
        self::assertSame([7], ExtFields::localizeIds('downloads', [7], 'en'), '不认识的表不处理');
        // 循环来源 rel: 在多语言站点上走这一步（单测里多语言开关是关的，按源码确认接线）
        self::assertStringContainsString("\$plan['ids'] = ExtFields::localizeIds(\$table, \$plan['ids'], siteLang());",
            (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxLoopQuery.php'));
    }

    public function testConditionOperatorsIncludingMultiValues(): void
    {
        $rule = static fn (string $op, string $value = 'v'): array => ['config' => ['conditions' => [['field' => 'mount', 'op' => $op, 'value' => $value]]]];
        $cases = [
            ['==', 'v', 'v', true], ['==', 'v', 'h', false], ['==', 'v', 'h,v', true], ['==', 'v', '', false],
            ['!=', 'v', 'h', true], ['!=', 'v', 'v', false], ['!=', 'v', 'h,v', false], ['!=', 'v', '', true],
            ['empty', '', '', true], ['empty', '', '  ', true], ['empty', '', 'x', false],
            ['not_empty', '', 'x', true], ['not_empty', '', '', false], ['not_empty', '', '0', true],
        ];
        foreach ($cases as [$op, $target, $actual, $expected]) {
            self::assertSame($expected, ExtFields::conditionsMatch($rule($op, $target), ['mount' => $actual]), "$op $target vs '$actual'");
        }
        // 多条规则同时满足（与）
        $both = ['config' => ['conditions' => [['field' => 'a', 'op' => '==', 'value' => '1'], ['field' => 'b', 'op' => 'not_empty', 'value' => '']]]];
        self::assertTrue(ExtFields::conditionsMatch($both, ['a' => '1', 'b' => 'x']));
        self::assertFalse(ExtFields::conditionsMatch($both, ['a' => '1', 'b' => '']));
        self::assertTrue(ExtFields::conditionsMatch(['config' => []], []), '没有条件 = 总是显示');
    }

    public function testTranslationCopyAndSaveSemantics(): void
    {
        $this->define('product', 'spec', 'text');
        $this->define('product', 'flag', 'switch');
        $this->define('product', 'tags', 'multi_select', [], ['options' => "a|A\nb|B"]);
        setMeta('product', 1, 'spec', 'zh value');
        setMeta('product', 1, 'flag', '1');
        setMeta('product', 1, 'tags', '');
        setMeta('product', 2, 'flag', '0');   // 译文已有自己的值
        ExtFields::copyValues('product', 1, 2);
        self::assertSame('zh value', ExtFields::raw('product', 2, 'spec'), '译文复制原文的值');
        self::assertSame('0', ExtFields::raw('product', 2, 'flag'), '不覆盖译文已有的值');
        self::assertNull(getMeta('product', 2, 'tags'), '空值不复制');
        ExtFields::copyValues('product', 1, 1);
        ExtFields::copyValues('product', 0, 2);

        // 没提交的字段（被条件 / 挂载隐藏）保持原值；带占位的「全不勾」写成清空
        ExtFields::save('product', 1, ['spec' => 'changed']);
        self::assertSame('1', ExtFields::raw('product', 1, 'flag'));
        ExtFields::save('product', 1, ['__present_flag' => '1', '__present_tags' => '1']);
        self::assertSame('0', ExtFields::raw('product', 1, 'flag'));
        self::assertSame('', ExtFields::raw('product', 1, 'tags'));
        self::assertSame('changed', ExtFields::raw('product', 1, 'spec'));
        ExtFields::save('product', -1, ['spec' => 'x']);
        ExtFields::save('nope', 1, ['spec' => 'x']);
        self::assertSame('changed', ExtFields::raw('product', 1, 'spec'), '非法归属 / id 不写');
        self::assertSame([], ExtFields::rows('product', 1, 'spec'), '不是重复器 → 没有行');
    }

    public function testSiteTemplatesCarryFieldValuesOfEveryOwner(): void
    {
        require_once ROOT_PATH . '/includes/SiteTemplateData.php';
        $portable = new \ReflectionMethod(\SiteTemplateData::class, 'metaOwnerPortable');
        foreach (['product', 'content', 'channel', 'product_category', 'site', 'extfield'] as $owner) {
            self::assertTrue($portable->invoke(null, $owner), $owner);
        }
        foreach (['redirect', 'wp_import', 'media'] as $owner) {
            self::assertFalse($portable->invoke(null, $owner), $owner . ' 属于具体站点，不导出');
        }
    }
}
