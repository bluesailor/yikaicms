<?php
/**
 * 样式预设收编为全局类（RFC-1 第 4 点，2.0.4）。
 *
 * 预设的本质是「带快照兜底的单选类」：每个预设一次性转成一个等价的全局类（颜色 / 背景 / 边框色 / 圆角），
 * 类带 important 标记——预设原是内联 !important，转换后仍压过元素本地颜色，页面外观不变。
 * 预设记下转成的 class_id 后：
 * - 渲染期：引用该预设的元素改挂这个类（不再输出内联），存量页面不必重存即跟随类的修改；
 * - 编辑器打开文档时：_global_style 改写为 _classes 引用，保存后文档里只剩「类」一个概念；
 * - 设计系统页：已转换的预设只读，指向类管理器。
 * 转换幂等；类创建属作者端付费能力，未授权或类表未迁移的站点保持原样（预设照旧内联渲染）。
 */

declare(strict_types=1);

final class BloxPresetClasses
{
    private const NAME_PREFIX = 'preset-';

    /** 预设 id => 已转成的 class_id（只含已转换的） @return array<string,string> */
    public static function map(): array
    {
        $map = [];
        foreach (BloxDesignSystem::snapshot()['styles'] as $style) {
            $classId = (string) ($style['class_id'] ?? '');
            if ($classId !== '') {
                $map[(string) $style['id']] = $classId;
            }
        }
        return $map;
    }

    /** 当前站点能否转换：有未转换的预设、类表就绪、两项作者端能力都已授权 */
    public static function canConvert(): bool
    {
        return BloxGlobalClasses::available()
            && BloxFeaturePolicy::allows('style_presets')
            && BloxFeaturePolicy::allows('global_classes')
            && self::pending() !== [];
    }

    /** @return list<array<string,mixed>> 尚未转换的预设 */
    public static function pending(): array
    {
        return array_values(array_filter(
            BloxDesignSystem::snapshot()['styles'],
            static fn(array $style): bool => (string) ($style['class_id'] ?? '') === ''
        ));
    }

    /**
     * 把未转换的预设逐个建成全局类并回写 class_id。类数量到上限等失败时跳过该预设（它继续按预设渲染）。
     *
     * @return array<string,string> 本次新转换的 预设 id => class_id
     * @psalm-suppress PossiblyUnusedReturnValue 后台入口（admin/blox_editor.php、blox_design.php）只触发转换，结果供测试核对
     */
    public static function convert(int $userId = 0): array
    {
        if (!self::canConvert()) {
            return [];
        }
        $created = [];
        foreach (self::pending() as $style) {
            try {
                $row = BloxGlobalClasses::mutate('class_add', [
                    'name' => self::uniqueName($style),
                    'category' => (string) ($style['category'] ?? ''),
                    'settings' => self::classSettings($style),
                    'user_id' => $userId,
                ], true);
            } catch (RuntimeException) {
                continue;
            }
            $created[(string) $style['id']] = (string) $row['class_id'];
        }
        if ($created === []) {
            return [];
        }
        try {
            $linked = BloxDesignSystem::linkStylesToClasses($created);
        } catch (RuntimeException) {
            $linked = [];
        }
        // 没能回写的（并发里别人先转了、或写锁冲突）：刚建的类放回收站，不留孤儿
        foreach (array_diff_key($created, array_flip($linked)) as $classId) {
            try {
                BloxGlobalClasses::mutate('class_trash', ['id' => $classId], true);
            } catch (RuntimeException) {
            }
        }
        return array_intersect_key($created, array_flip($linked));
    }

    /**
     * 预设 → 类设置（纯函数）。预设没有边框宽度，填了边框色即 1px 实线，与类的默认一致。
     *
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    public static function classSettings(array $style): array
    {
        return BloxGlobalClasses::normalizeSettings([
            'text_color' => (string) ($style['color'] ?? ''),
            'bg_color' => (string) ($style['background'] ?? ''),
            'border_color' => (string) ($style['border_color'] ?? ''),
            'radius' => (string) ($style['radius'] ?? 'none'),
            'important' => true,
            'from_preset' => (string) ($style['id'] ?? ''),
        ]);
    }

    /**
     * 文档改写（纯函数）：引用已转换预设的元素改挂对应类，去掉 _global_style / 快照。
     * 类已挂满（MAX_PER_ELEMENT）的元素保持原样，继续按预设渲染。
     *
     * @param array<int,mixed> $sections
     * @param array<string,string>|null $map 预设 id => class_id；null 取当前站点
     * @return array<int,mixed>
     * @psalm-suppress PossiblyUnusedMethod 调用方是 admin/blox_editor.php（不在 Psalm 分析范围）
     */
    public static function migrateSections(array $sections, ?array $map = null): array
    {
        $map ??= self::map();
        if ($map === []) {
            return $sections;
        }
        foreach ($sections as $si => $section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as $ci => $column) {
                if (!is_array($column) || !is_array($column['elements'] ?? null)) {
                    continue;
                }
                foreach ($column['elements'] as $ei => $element) {
                    if (is_array($element)) {
                        $sections[$si]['columns'][$ci]['elements'][$ei] = self::migrateElement($element, $map);
                    }
                }
            }
        }
        return $sections;
    }

    /**
     * 渲染期：元素 data 里引用了已转换预设时，等价改写为挂类（不改存储）。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function renderData(array $data): array
    {
        $id = $data['_global_style'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            return $data;
        }
        $map = self::map();
        return isset($map[trim($id)]) ? self::migrateData($data, $map) : $data;
    }

    /** @param array<string,mixed> $element @param array<string,string> $map @return array<string,mixed> */
    private static function migrateElement(array $element, array $map): array
    {
        if (!is_array($element['data'] ?? null)) {
            return $element;
        }
        $data = self::migrateData($element['data'], $map);
        if (is_array($data['children'] ?? null)) {
            foreach ($data['children'] as $index => $child) {
                if (is_array($child)) {
                    $data['children'][$index] = self::migrateElement($child, $map);
                }
            }
        }
        $element['data'] = $data;
        return $element;
    }

    /** @param array<string,mixed> $data @param array<string,string> $map @return array<string,mixed> */
    private static function migrateData(array $data, array $map): array
    {
        $id = is_string($data['_global_style'] ?? null) ? trim($data['_global_style']) : '';
        if ($id === '' || !isset($map[$id])) {
            return $data;
        }
        $classes = array_values(array_filter(
            is_array($data['_classes'] ?? null) ? $data['_classes'] : [],
            static fn(mixed $classId): bool => is_string($classId) && $classId !== $map[$id]
        ));
        if (count($classes) >= BloxGlobalClasses::MAX_PER_ELEMENT) {
            return $data;
        }
        // 预设排在最前：类之间按样式表顺序决胜，挂载顺序不影响外观，放前面只为面板里一眼看到
        array_unshift($classes, $map[$id]);
        $data['_classes'] = $classes;
        unset($data['_global_style'], $data['_global_style_snapshot']);
        return $data;
    }

    /** 类名：预设名能转成 ASCII 就用它，否则用预设 id；重名加序号。 */
    private static function uniqueName(array $style): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($style['name'] ?? ''))), '-');
        if (strlen($slug) < 2) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($style['id'] ?? 'style'))), '-');
        }
        $base = substr(self::NAME_PREFIX . $slug, 0, 44);
        $base = rtrim($base, '-');
        $name = $base;
        for ($n = 2; bloxGlobalClassModel()->findActiveByName($name) !== null; $n++) {
            $name = $base . '-' . $n;
        }
        return $name;
    }
}
