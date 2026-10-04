<?php
/**
 * 高级字段（2.0.4）：类型校验、配置归一、条件逻辑与挂载位置、保存 / 必填、取值与展示、
 * 重复器 / 关联做循环来源、动态标签、字段表格元素。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BloxDynamicTags;
use BloxLoopQuery;
use ExtFields;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TagEngine;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class ExtFieldsTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, owner_id INTEGER, meta_key TEXT, meta_value TEXT, created_at INTEGER, updated_at INTEGER)',
            'CREATE UNIQUE INDEX uk_owner_key_metas ON metas (owner_type, owner_id, meta_key)',
            "CREATE TABLE extfields (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, field_key TEXT, field_name TEXT, field_type TEXT DEFAULT 'text',
                options TEXT, placeholder TEXT DEFAULT '', help_text TEXT DEFAULT '', is_required INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1, created_at INTEGER DEFAULT 0)",
            "CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER DEFAULT 0, title TEXT NOT NULL, model TEXT, summary TEXT,
                cover TEXT, price REAL DEFAULT 0, status INTEGER DEFAULT 1, is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                is_new INTEGER DEFAULT 0, views INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL)",
            "CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT,
                image TEXT DEFAULT '', description TEXT DEFAULT '', status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../fixtures/ext-fields-stubs.php';
        require_once ROOT_PATH . '/includes/ExtFields.php';
        ExtFields::flushCache();
    }

    /** @param array<string,mixed> $config */
    private function define(string $owner, string $key, string $type, array $config = [], array $extra = []): int
    {
        $id = (int) $this->insertRow('extfields', $extra + ['owner_type' => $owner, 'field_key' => $key, 'field_name' => ucfirst($key), 'field_type' => $type]);
        ExtFields::saveConfig($id, $type, $config);
        return $id;
    }

    private const SPEC_SUBS = ['sub_fields' => [
        ['key' => 'model', 'name' => 'Model', 'type' => 'text'],
        ['key' => 'load', 'name' => 'Load', 'type' => 'number'],
        ['key' => 'drawing', 'name' => 'Drawing', 'type' => 'file'],
        ['key' => 'buy', 'name' => 'Buy', 'type' => 'link'],
    ]];

    public function testSimpleTypesAreSanitized(): void
    {
        self::assertSame('ab', ExtFields::sanitize(['field_type' => 'text'], " a\x07b "));
        self::assertSame('', ExtFields::sanitize(['field_type' => 'text'], ['array']));
        self::assertSame('12.5', ExtFields::sanitize(['field_type' => 'number'], ' 12.50 '));
        self::assertSame('', ExtFields::sanitize(['field_type' => 'number'], '12kg'));
        self::assertSame('2026-10-04', ExtFields::sanitize(['field_type' => 'date'], '2026-10-04'));
        self::assertSame('', ExtFields::sanitize(['field_type' => 'date'], '2026-02-30'));
        self::assertSame('1', ExtFields::sanitize(['field_type' => 'switch'], 'on'));
        self::assertSame('0', ExtFields::sanitize(['field_type' => 'switch'], 'yes please'));
        $choice = ['field_type' => 'select', 'options' => "steel|Steel\nbrass|Brass"];
        self::assertSame('brass', ExtFields::sanitize($choice, 'brass'));
        self::assertSame('', ExtFields::sanitize($choice, 'gold'));
        self::assertSame('steel,brass', ExtFields::sanitize(['field_type' => 'multi_select', 'options' => '{"steel":"Steel","brass":"Brass"}'], ['steel', 'gold', 'brass', 'steel']));
        self::assertSame('', ExtFields::sanitize(['field_type' => 'image'], 'javascript:alert(1)'));
        self::assertSame('/a.jpg,/b.jpg', ExtFields::sanitize(['field_type' => 'images'], '/a.jpg, javascript:x ,/b.jpg'));
        self::assertSame('#1e40af', ExtFields::sanitize(['field_type' => 'color'], '#1E40AF'));
        self::assertSame('', ExtFields::sanitize(['field_type' => 'color'], 'red;background:url(x)'));
        self::assertSame('{"url":"/contact/","title":"Contact","target":"_blank"}',
            ExtFields::sanitize(['field_type' => 'link'], ['url' => '/contact/', 'title' => 'Contact', 'target' => '_blank']));
        self::assertSame('', ExtFields::sanitize(['field_type' => 'link'], ['url' => 'javascript:alert(1)', 'title' => 'x']));
        self::assertStringNotContainsString('<script', ExtFields::sanitize(['field_type' => 'richtext'], '<p>ok</p><script>x</script>'));
    }

    public function testRepeaterGroupAndRelationshipValues(): void
    {
        $repeater = ['field_type' => 'repeater', 'config' => self::SPEC_SUBS + ['max' => 2]];
        $stored = ExtFields::sanitize($repeater, [
            ['model' => 'SE7A', 'load' => '35', 'drawing' => '/a.pdf', 'buy' => ['url' => '/buy/', 'title' => 'Buy']],
            ['model' => '', 'load' => '', 'drawing' => ''],   // 空行丢掉
            ['model' => 'SE7B', 'load' => 'x', 'evil' => '<b>'],
            ['model' => 'SE7C'],                              // 超出最多行数
        ]);
        $rows = json_decode($stored, true);
        self::assertCount(2, $rows);
        self::assertSame(['model' => 'SE7A', 'load' => '35', 'drawing' => '/a.pdf', 'buy' => '{"url":"/buy/","title":"Buy"}'], $rows[0]);
        self::assertSame(['model' => 'SE7B', 'load' => '', 'drawing' => '', 'buy' => ''], $rows[1], '子字段按类型收紧，未知键丢弃');
        self::assertSame('', ExtFields::sanitize($repeater, '[]'));

        $group = ['field_type' => 'group', 'config' => ['sub_fields' => [['key' => 'name', 'name' => 'Name', 'type' => 'text'], ['key' => 'vip', 'name' => 'VIP', 'type' => 'switch']]]];
        self::assertSame('{"name":"Ann","vip":"1"}', ExtFields::sanitize($group, ['name' => 'Ann', 'vip' => '1']));
        self::assertSame('', ExtFields::sanitize($group, ['name' => '', 'vip' => '0']), '只有开关默认值 = 没填');

        self::assertSame('5,3', ExtFields::sanitize(['field_type' => 'relationship', 'config' => ['max' => 2]], '5,3,5,0,9'));
    }

    public function testConfigIsNormalizedToAWhitelist(): void
    {
        $config = ExtFields::normalizeConfig('repeater', [
            'sub_fields' => [
                ['key' => 'Model', 'name' => 'Model', 'type' => 'text'],
                ['key' => 'model', 'name' => 'Dup', 'type' => 'text'],
                ['key' => '1bad', 'type' => 'text'],
                ['key' => 'nested', 'type' => 'repeater'],
                ['key' => 'size', 'type' => 'select', 'options' => "s|S\nm|M"],
            ],
            'min' => 2, 'max' => 1, 'button_label' => 'Add model', 'target' => 'product',
            'conditions' => [['field' => 'kind', 'op' => '==', 'value' => 'custom'], ['field' => 'x', 'op' => 'LIKE']],
            'location' => ['3', 0, 'x', 3, 7],
        ]);
        self::assertSame([
            'sub_fields' => [
                ['key' => 'model', 'name' => 'Model', 'type' => 'text'],
                ['key' => 'size', 'name' => 'size', 'type' => 'select', 'options' => "s|S\nm|M"],
            ],
            'min' => 2, 'max' => 2, 'button_label' => 'Add model',
            'conditions' => [['field' => 'kind', 'op' => '==', 'value' => 'custom']],
            'location' => [3, 7],
        ], $config);
        self::assertSame(['target' => 'product'], ExtFields::normalizeConfig('relationship', ['target' => 'bad target!']));
        self::assertTrue(ExtFields::configUsesPro(['location' => [1]]));
        self::assertFalse(ExtFields::configUsesPro(['sub_fields' => []]));
    }

    public function testSaveRequiredConditionsAndLocation(): void
    {
        $this->define('product', 'kind', 'select', [], ['options' => "std|Standard\ncustom|Custom"]);
        $this->define('product', 'size', 'text', ['conditions' => [['field' => 'kind', 'op' => '==', 'value' => 'custom']]], ['is_required' => 1]);
        $this->define('product', 'torque', 'text', ['location' => [5]], ['is_required' => 1]);
        $this->define('product', 'finish', 'multi_select', [], ['options' => "a|A\nb|B"]);

        self::assertNull(ExtFields::missingRequired('product', ['kind' => 'std'], 0), '条件不满足、分类不符：隐藏字段不校验');
        self::assertSame('Size', ExtFields::missingRequired('product', ['kind' => 'custom'], 0));
        self::assertSame('Torque', ExtFields::missingRequired('product', ['kind' => 'std'], 5));

        ExtFields::save('product', 9, ['kind' => 'custom', 'size' => 'XL', 'finish' => ['a', 'b'], 'ghost' => 'x']);
        $saved = getAllMeta('product', 9);
        ksort($saved);
        self::assertSame(['finish' => 'a,b', 'kind' => 'custom', 'size' => 'XL'], $saved, '只写已定义的字段');
        ExtFields::save('product', 9, ['__present_finish' => '1']);
        self::assertSame('', getMeta('product', 9, 'finish'), '多选全不勾 = 清空');
        self::assertSame('XL', getMeta('product', 9, 'size'), '没提交的字段保持原值');

        ExtFields::copyValues('product', 9, 10);
        self::assertSame('custom', getMeta('product', 10, 'kind'));
    }

    public function testTextAndHtmlOutput(): void
    {
        $this->define('product', 'kind', 'select', [], ['options' => "custom|Custom made"]);
        $this->define('product', 'buy', 'link');
        $this->define('product', 'contact', 'group', ['sub_fields' => [['key' => 'name', 'name' => 'Name', 'type' => 'text'], ['key' => 'site', 'name' => 'Site', 'type' => 'link']]]);
        $this->define('product', 'specs', 'repeater', self::SPEC_SUBS);
        setMeta('product', 1, 'kind', 'custom');
        setMeta('product', 1, 'buy', '{"url":"/buy/","title":"Buy <now>"}');
        setMeta('product', 1, 'contact', '{"name":"Ann","site":"{\"url\":\"https://a.example\"}"}');
        setMeta('product', 1, 'specs', '[{"model":"A"},{"model":"B"}]');
        setMeta('product', 1, 'legacy_param', '73:1');

        self::assertSame('Custom made', ExtFields::textFor('product', 1, 'kind'));
        self::assertSame('/buy/', ExtFields::textFor('product', 1, 'buy'));
        self::assertSame('Buy <now>', ExtFields::textFor('product', 1, 'buy', 'title'));
        self::assertSame('Ann', ExtFields::textFor('product', 1, 'contact', 'name'));
        self::assertSame('https://a.example', ExtFields::textFor('product', 1, 'contact', 'site'));
        self::assertSame('2', ExtFields::textFor('product', 1, 'specs'), '重复器的纯文本是行数');
        self::assertSame('73:1', ExtFields::textFor('product', 1, 'legacy_param'), '没定义的键原样读');
        self::assertSame([['model' => 'A'], ['model' => 'B']], ExtFields::rows('product', 1, 'specs'));

        $link = ExtFields::field('product', 'buy');
        self::assertSame('<a href="/buy/" class="text-primary hover:underline">Buy &lt;now&gt;</a>', ExtFields::html($link, '{"url":"/buy/","title":"Buy <now>"}'));
        self::assertStringContainsString('download', ExtFields::html(['type' => 'file'], '/files/spec%20sheet.pdf'));
        self::assertStringContainsString('spec sheet.pdf', ExtFields::html(['type' => 'file'], '/files/spec%20sheet.pdf'));
        self::assertSame("a&lt;b<br />\n", ExtFields::html(['type' => 'textarea'], "a<b\n"));
    }

    public function testRepeaterAndRelationshipLoopsAndTags(): void
    {
        require_once ROOT_PATH . '/includes/TagEngine.php';
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $this->define('product', 'specs', 'repeater', self::SPEC_SUBS);
        $this->define('product', 'related', 'relationship', ['target' => 'product']);
        $this->define('product', 'mount', 'select', [], ['options' => "v|Vertical"]);
        $this->define('site', 'certs', 'repeater', ['sub_fields' => [['key' => 'name', 'name' => 'Name', 'type' => 'text']]]);
        $this->define('site', 'factory', 'text');
        foreach (['Drive', 'Ring', 'Gear'] as $title) {
            $this->insertRow('products', ['title' => $title]);
        }
        setMeta('product', 1, 'specs', '[{"model":"SE7A","load":"35","buy":"{\"url\":\"/buy/a/\",\"title\":\"Buy A\"}"},{"model":"SE7B","load":"42"}]');
        setMeta('product', 1, 'related', '3,2');
        setMeta('product', 1, 'mount', 'v');
        setMeta('site', 0, 'certs', '[{"name":"ISO 9001"},{"name":"CE"}]');
        setMeta('site', 0, 'factory', '20,000 m²');
        TagEngine::setItem(['id' => 1, 'title' => 'Drive', 'category_id' => 0], 'product');
        BloxLoopQuery::resetForTests();

        $rows = BloxLoopQuery::run(['source' => 'field:specs', 'limit' => 10], '');
        self::assertSame('row', $rows['kind']);
        self::assertSame(['SE7A', 'SE7B'], array_column($rows['rows'], 'model'));
        self::assertSame(['Gear', 'Ring'], array_column(BloxLoopQuery::run(['source' => 'rel:related', 'limit' => 10], '')['rows'], 'title'), '关联按选择顺序');
        self::assertSame([], BloxLoopQuery::run(['source' => 'field:mount', 'limit' => 10], '')['rows'], '不是重复器 → 空态');

        $html = BlockRenderer::render((string) json_encode([[
            'settings' => [],
            'columns' => [['elements' => [
                ['id' => 'loop1', 'type' => 'div', 'data' => ['_query' => ['source' => 'field:specs', 'limit' => 10], 'children' => [
                    ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'R{{loop.index}}:{{loop.model}}/{{loop.load}}/{{loop.buy.title | -}}']],
                ]]],
                ['id' => 'loop2', 'type' => 'div', 'data' => ['_query' => ['source' => 'option:certs', 'limit' => 10], 'children' => [
                    ['id' => 'h2', 'type' => 'heading', 'data' => ['text' => 'C:{{loop.name}}']],
                ]]],
                ['id' => 'h3', 'type' => 'heading', 'data' => ['text' => 'F:{{option.factory}} M:{{product.meta.mount | none}}']],
                ['id' => 't1', 'type' => 'field-table', 'data' => ['field' => 'specs']],
            ]]],
        ]]));
        self::assertStringContainsString('R1:SE7A/35/Buy A', $html);
        self::assertStringContainsString('R2:SE7B/42/-', $html);
        self::assertStringContainsString('C:ISO 9001', $html);
        self::assertStringContainsString('C:CE', $html);
        self::assertStringContainsString('F:20,000 m²', $html);
        self::assertMatchesRegularExpression('#<th scope="col"[^>]*>Model</th>#', $html, '字段表格：表头是子字段名');
        self::assertStringContainsString('>SE7B</td>', $html);
        self::assertStringContainsString('<a href="/buy/a/"', $html);
        // 产品详情模板的当前产品（ProductTemplateDocument::withProduct，与真实产品页同一入口）
        $tag = \ProductTemplateDocument::withProduct(['id' => 1, 'title' => 'Drive'], static fn (): string => BloxDynamicTags::resolveText('{{product.meta.mount}}|{{product.meta.specs}}'));
        self::assertSame('Vertical|2', $tag);
        self::assertNull(BloxDynamicTags::providerValue('product.meta.mount'), '不在产品详情模板里：没有当前产品');
    }
}
