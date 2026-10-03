<?php
/** 样式预设收编为全局类（RFC-1 第 4 点，2.0.4）：转换等价、文档改写、渲染期改挂类。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxDesignSystem;
use BloxGlobalClasses;
use BloxPresetClasses;
use BlockRenderer;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxPresetClassesTest extends TestCase
{
    private const STYLE = [
        'id' => 's_card', 'name' => 'Card', 'category' => 'component',
        'color' => 'var(--yk-color-text)', 'background' => '#ffffff', 'border_color' => '#e5e7eb', 'radius' => 'md',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        BloxGlobalClasses::resetForTests();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_global_classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '',
                settings TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                trashed_at INTEGER NOT NULL DEFAULT 0,
                modified INTEGER NOT NULL DEFAULT 0,
                revision INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT UNIQUE, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INT DEFAULT 0)",
            "CREATE TABLE blox_class_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testConvertedClassKeepsThePresetDeclarationsAndPriority(): void
    {
        $settings = BloxPresetClasses::classSettings(self::STYLE);
        self::assertSame([
            'text_color' => 'var(--yk-color-text)', 'bg_color' => '#ffffff', 'border_color' => '#e5e7eb',
            'radius' => 'md', 'important' => true, 'from_preset' => 's_card',
        ], $settings);
        self::assertSame(
            '.yk-c-preset-card:not(yk-none){color:var(--yk-color-text)!important;background-color:#ffffff!important;'
            . 'border-color:#e5e7eb!important;border-radius:0.5rem!important;border-style:solid!important;border-width:1px!important}',
            BloxGlobalClasses::classRules('preset-card', $settings),
            '与预设的内联声明同一组属性、同样 !important'
        );
        self::assertStringNotContainsString('!important', BloxGlobalClasses::classRules('plain', ['bg_color' => '#ffffff']), '普通类不受影响');
        self::assertArrayNotHasKey('from_preset', BloxGlobalClasses::normalizeSettings(['from_preset' => 'bad id;']));
    }

    public function testMigrateSectionsRewritesPresetReferencesIncludingNestedChildren(): void
    {
        $map = ['s_card' => 'gc_aaaaaaaaaaaa'];
        $sections = [[
            'columns' => [['elements' => [
                ['type' => 'heading', 'data' => ['_global_style' => 's_card', '_global_style_snapshot' => ['color' => '#000000'], '_classes' => ['gc_bbbbbbbbbbbb']]],
                ['type' => 'container', 'data' => ['children' => [
                    ['type' => 'text', 'data' => ['_global_style' => 's_card']],
                ]]],
                ['type' => 'text', 'data' => ['_global_style' => 's_other', '_global_style_snapshot' => ['color' => '#111111']]],
            ]]],
        ]];
        $out = BloxPresetClasses::migrateSections($sections, $map);
        $elements = $out[0]['columns'][0]['elements'];
        self::assertSame(['_classes' => ['gc_aaaaaaaaaaaa', 'gc_bbbbbbbbbbbb']], $elements[0]['data']);
        self::assertSame(['_classes' => ['gc_aaaaaaaaaaaa']], $elements[1]['data']['children'][0]['data']);
        self::assertSame('s_other', $elements[2]['data']['_global_style'], '未转换的预设保持原样');

        $full = array_map(static fn(int $n): string => 'gc_' . str_pad((string) $n, 12, '0', STR_PAD_LEFT), range(1, BloxGlobalClasses::MAX_PER_ELEMENT));
        $crowded = [['columns' => [['elements' => [['type' => 'text', 'data' => ['_global_style' => 's_card', '_classes' => $full]]]]]]];
        self::assertSame($crowded, BloxPresetClasses::migrateSections($crowded, $map), '类已挂满时继续按预设渲染');
        self::assertSame($sections, BloxPresetClasses::migrateSections($sections, []));
    }

    public function testRenderingFollowsTheClassOnceThePresetIsConverted(): void
    {
        $row = BloxGlobalClasses::mutate('class_add', [
            'name' => 'preset-card', 'settings' => BloxPresetClasses::classSettings(self::STYLE),
        ], true);
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [], 'styles' => [self::STYLE + ['class_id' => $row['class_id']]],
        ], JSON_THROW_ON_ERROR);
        BloxGlobalClasses::resetForTests();

        self::assertSame(['s_card' => $row['class_id']], BloxPresetClasses::map());
        $html = BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [[
                'type' => 'heading', 'id' => 'e1', 'data' => ['text' => 'Hi', '_global_style' => 's_card', '_global_style_snapshot' => ['color' => '#000000']],
            ]]]],
        ]]));
        self::assertStringContainsString('yk-c-preset-card', $html, '存量文档未重存也改挂类');
        self::assertStringNotContainsString('data-yk-global-style', $html);
        self::assertStringNotContainsString('color:var(--yk-color-text)!important', $html, '不再输出预设的内联声明');
    }

    public function testUnconvertedPresetStillRendersInline(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [], 'styles' => [self::STYLE],
        ], JSON_THROW_ON_ERROR);
        self::assertSame([], BloxPresetClasses::map());
        $html = BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [[
                'type' => 'heading', 'id' => 'e1', 'data' => ['text' => 'Hi', '_global_style' => 's_card'],
            ]]]],
        ]]));
        self::assertStringContainsString('data-yk-global-style="s_card"', $html);
        self::assertStringContainsString('background-color:#ffffff!important', $html);
    }

    public function testConvertCreatesEquivalentClassesOnceAndLinksThePresets(): void
    {
        if (!\BloxFeaturePolicy::allows('style_presets') || !\BloxFeaturePolicy::allows('global_classes')) {
            self::assertSame([], BloxPresetClasses::convert(), '未授权不转换');
            return;
        }
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [], 'styles' => [self::STYLE, ['id' => 's_cn', 'name' => '强调卡片', 'background' => '#000000']],
        ], JSON_THROW_ON_ERROR);
        $created = BloxPresetClasses::convert(7);
        self::assertSame(['s_card', 's_cn'], array_keys($created));
        $catalog = BloxGlobalClasses::catalog();
        self::assertSame('preset-card', $catalog[$created['s_card']]['name']);
        self::assertSame('preset-s-cn', $catalog[$created['s_cn']]['name'], '中文名退回用预设 id');
        self::assertTrue($catalog[$created['s_card']]['settings']['important']);

        $stored = json_decode((string) db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = ?", [BloxDesignSystem::SETTING_KEY]), true);
        self::assertSame(array_values($created), array_column($stored['styles'], 'class_id'), '预设回写 class_id');

        // 下一次请求读到已回写的预设：不再重复建类
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode($stored, JSON_THROW_ON_ERROR);
        self::assertFalse(BloxPresetClasses::canConvert());
        self::assertSame([], BloxPresetClasses::convert());
        self::assertCount(2, BloxGlobalClasses::catalog());
    }
}
