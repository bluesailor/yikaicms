<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/admin/includes/form_presets.php';
require_once ROOT_PATH . '/admin/includes/form_fields.php';

/** 表单设计的常用预设：字段标签能被解析，文案三语齐全。 */
final class FormDesignPresetsTest extends TestCase
{
    public function testEveryPresetProducesParsableUniqueFieldsAndASubmitButton(): void
    {
        // 与 includes/functions.php parseFormTags() 同一正则，避免预设写出解析不了的标签
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertSame(1, preg_match("/preg_match_all\\('(\\/\\\\\\[\\(text\\|[^']+)'/", $functions, $m));
        $pattern = $m[1];

        $presets = formDesignPresets();
        self::assertSame(['contact', 'appointment', 'event', 'partner'], array_column($presets, 'key'));
        self::assertSame(count($presets), count(array_unique(array_column($presets, 'slug'))));
        foreach ($presets as $preset) {
            self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $preset['slug']);
            $blueprint = formDesignPresetBlueprints()[$preset['key']];
            preg_match_all($pattern, $preset['template_text'], $tags, PREG_SET_ORDER);
            self::assertCount(count($blueprint['fields']), $tags, $preset['key']);
            $names = array_map(static fn(array $tag): string => $tag[3], $tags);
            self::assertSame(array_column($blueprint['fields'], 1), $names);
            self::assertSame(count($names), count(array_unique($names)), $preset['key'] . ' 字段名重复');
            self::assertStringContainsString('[submit "', $preset['template_text']);
        }
    }

    public function testPresetTextExistsInEveryAdminLanguage(): void
    {
        $keys = [];
        foreach (formDesignPresetBlueprints() as $key => $blueprint) {
            array_push($keys, $blueprint['name'], $blueprint['success'], $blueprint['submit'], 'fd_preset_' . $key, 'fd_preset_' . $key . '_desc');
            foreach ($blueprint['fields'] as $field) {
                $keys[] = $field[3];
                array_push($keys, ...$field[4]);
            }
        }
        foreach (['zh-CN', 'en', 'ja'] as $language) {
            $strings = require ROOT_PATH . '/lang/' . $language . '.php';
            foreach (array_unique($keys) as $key) {
                self::assertArrayHasKey($key, $strings, $language . ' ' . $key);
                self::assertStringNotContainsString('"', (string) $strings[$key], $language . ' ' . $key);
            }
        }
    }

    public function testSubmissionDetailShowsCustomFieldsWithTemplateLabels(): void
    {
        $preset = formDesignPresets()[1];
        $labels = formTemplateFieldLabels($preset['template_text']);
        self::assertSame('fd_pre_date', $labels['appointment_date']);
        self::assertSame('fd_pre_time_slot', $labels['time_slot']);

        $cjk = formTemplateFieldLabels('<div><label>预约时段 <span class="text-red-500">*</span></label>
    [select* time_slot "请选择" "上午"]</div><label>备注</label>[textarea content]');
        self::assertSame(['time_slot' => '预约时段', 'content' => '备注'], $cjk);
        self::assertSame(['phone' => '电话'], formTemplateFieldLabels('[{"key":"phone","label":"电话"}]'));

        $extra = json_encode(['name' => '张三', 'time_slot' => '上午', 'people' => '', 'source' => '官网, 朋友推荐', 'unknown_key' => 'x'], JSON_UNESCAPED_UNICODE);
        self::assertSame([
            ['key' => 'time_slot', 'label' => '预约时段', 'value' => '上午'],
            ['key' => 'source', 'label' => 'source', 'value' => '官网, 朋友推荐'],
            ['key' => 'unknown_key', 'label' => 'unknown_key', 'value' => 'x'],
        ], formSubmissionExtraFields((string) $extra, $cjk));
        self::assertSame([], formSubmissionExtraFields('not json', $cjk));
    }
}
