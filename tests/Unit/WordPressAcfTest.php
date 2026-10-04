<?php
/** ACF → 扩展字段（2.0.4）：挂载规则、字段名、类型映射与降级、值转换。 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/migrate/WordPressSource.php';
require_once ROOT_PATH . '/includes/migrate/WordPressAcf.php';
require_once ROOT_PATH . '/includes/ExtFields.php';

final class WordPressAcfTest extends TestCase
{
    private static function rule(string $param, string $value, string $operator = '=='): array
    {
        return ['param' => $param, 'operator' => $operator, 'value' => $value];
    }

    public function testLocationRulesMapToOwners(): void
    {
        $unknown = [];
        $owners = WordPressAcf::owners([
            [self::rule('post_type', 'product')],
            [self::rule('post_type', 'page'), self::rule('page_template', 'tpl-contact.php')],
            [self::rule('taxonomy', 'product_cat')],
            [self::rule('options_page', 'acf-options-company')],
            [self::rule('post_taxonomy', 'product_cat:worm-drive')],
            [self::rule('post_type', 'post', '!=')],
            [self::rule('user_role', 'editor')],
        ], $unknown);
        self::assertSame([
            ['owner' => 'product', 'terms' => []],
            ['owner' => 'channel', 'terms' => []],
            ['owner' => 'product_category', 'terms' => []],
            ['owner' => 'site', 'terms' => []],
            ['owner' => 'product', 'terms' => ['product_cat' => ['worm-drive']]],
        ], $owners);
        self::assertSame(['post_type != post', 'user_role == editor'], $unknown);
    }

    public function testFieldNamesBecomeValidKeys(): void
    {
        self::assertSame('spec_table', WordPressAcf::key('spec_table'));
        self::assertSame('load_kn', WordPressAcf::key('Load-kN'));
        self::assertSame('f_3d_model', WordPressAcf::key('3d model'));
    }

    public function testDefinitionsMapTypesAndDegradeSubFields(): void
    {
        $field = static fn (string $name, array $settings, array $children = []): array => ['id' => 1, 'name' => $name, 'label' => ucfirst($name), 'key' => 'field_' . $name, 'order' => 0, 'settings' => $settings, 'children' => $children];
        $skipped = null;
        self::assertNull(WordPressAcf::definition($field('t', ['type' => 'tab']), $skipped));
        self::assertSame('', $skipped, '布局字段静默跳过');
        self::assertNull(WordPressAcf::definition($field('m', ['type' => 'google_map']), $skipped));
        self::assertSame('google_map', $skipped);

        $select = WordPressAcf::definition($field('mount', ['type' => 'select', 'multiple' => 1, 'required' => 1, 'choices' => ['h' => 'Horizontal', 'v' => 'Vertical']]));
        self::assertSame('multi_select', $select['field_type']);
        self::assertSame("h|Horizontal\nv|Vertical", $select['options']);
        self::assertSame(1, $select['is_required']);

        $flex = WordPressAcf::definition($field('blocks', ['type' => 'flexible_content'], [
            $field('heading', ['type' => 'text']),
            $field('body', ['type' => 'wysiwyg']),
            $field('photos', ['type' => 'gallery']),
            $field('inner', ['type' => 'repeater']),
        ]));
        self::assertSame('repeater', $flex['field_type']);
        self::assertSame(['acf_layout', 'heading', 'body', 'photos'], array_column($flex['config']['sub_fields'], 'key'));
        self::assertSame(['text', 'text', 'textarea', 'image'], array_column($flex['config']['sub_fields'], 'type'), '富文本降为多行文本，相册取单图，嵌套重复器跳过');

        $one = WordPressAcf::definition($field('dealer', ['type' => 'post_object', 'post_type' => ['product']]));
        self::assertSame(['target' => 'product', 'max' => 1], $one['config']);
    }

    public function testValuesAreConverted(): void
    {
        $resolve = static fn (string $kind, int $id): string => match ($kind) {
            'attachment' => $id === 7 ? '/wp-content/uploads/a.jpg' : '',
            'content' => $id === 40 ? '12' : '0',
            default => '',
        };
        $field = static fn (string $name, array $settings, array $children = []): array => ['id' => 1, 'name' => $name, 'label' => $name, 'key' => 'k', 'order' => 0, 'settings' => $settings, 'children' => $children];
        $meta = [
            'photo' => '7', 'when' => '20240131', 'links' => serialize(['40', '41']),
            'blocks' => serialize(['hero', 'text']), 'blocks_0_heading' => 'Hi', 'blocks_1_heading' => 'There',
            'contact_name' => 'Ann',
        ];
        self::assertSame('/wp-content/uploads/a.jpg', WordPressAcf::value($field('photo', ['type' => 'image']), $meta, '', $resolve));
        self::assertSame('2024-01-31', WordPressAcf::value($field('when', ['type' => 'date_picker']), $meta, '', $resolve));
        self::assertSame('12', WordPressAcf::value($field('links', ['type' => 'relationship', 'post_type' => ['post']]), $meta, '', $resolve), '没导入的关联条目丢掉');
        self::assertSame([['acf_layout' => 'hero', 'heading' => 'Hi'], ['acf_layout' => 'text', 'heading' => 'There']],
            WordPressAcf::value($field('blocks', ['type' => 'flexible_content'], [$field('heading', ['type' => 'text'])]), $meta, '', $resolve));
        self::assertSame(['name' => 'Ann'], WordPressAcf::value($field('contact', ['type' => 'group'], [$field('name', ['type' => 'text'])]), $meta, '', $resolve));
        self::assertSame('', WordPressAcf::value($field('missing', ['type' => 'text']), $meta, '', $resolve));
    }
}
