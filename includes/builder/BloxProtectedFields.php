<?php
declare(strict_types=1);

/** Compare protected authoring fields against server-owned document data only. */
final class BloxProtectedFields
{
    /** 站点字段与循环字段绑定；fallback、截断长度属于普通展示设置，不在保护范围内。 */
    private const BINDING_KEYS = [
        'site_field', 'site_image_field', 'site_text_field', 'site_url_field',
        'loop_field', 'loop_url_field', 'loop_alt_field', 'loop_link_field', 'loop_text_field',
    ];

    public static function forValidation(array $sections, array $trusted, array $denied): array
    {
        $before = [];
        $after = [];
        self::project($trusted, $denied, $before);
        $stripped = self::project($sections, $denied, $after);
        ksort($before);
        ksort($after);
        if ($before !== $after) {
            throw new RuntimeException(__('blox_protected_fields_changed'));
        }
        return $stripped;
    }

    private static function project(array $sections, array $denied, array &$protected): array
    {
        $seen = [];
        foreach ($sections as &$section) {
            $sectionId = self::identity($section, $seen);
            if (in_array('display_conditions', $denied, true) && !empty($section['settings']['_conditions'])) {
                if ($sectionId === '') throw new RuntimeException(__('blox_protected_fields_changed'));
                $protected['section:' . $sectionId] = $section['settings']['_conditions'];
                unset($section['settings']['_conditions']);
            }
            foreach ($section['columns'] ?? [] as $ci => $column) {
                $columnId = (string) ($column['id'] ?? $ci);
                foreach ($column['elements'] ?? [] as $ei => $element) {
                    $section['columns'][$ci]['elements'][$ei] = self::element(
                        $element, $sectionId . '/' . $columnId, $denied, $protected, $seen
                    );
                }
            }
        }
        unset($section);
        return $sections;
    }

    private static function identity(array $node, array &$seen): string
    {
        $id = (string) ($node['id'] ?? '');
        if ($id === '') return '';
        if (isset($seen[$id])) {
            throw new RuntimeException(__('blox_protected_fields_changed'));
        }
        $seen[$id] = true;
        return $id;
    }

    private static function element(array $element, string $parent, array $denied, array &$protected, array &$seen): array
    {
        $id = self::identity($element, $seen);
        $data = $element['data'] ?? [];
        $fields = [];
        $keys = [];
        $loopHost = false;
        if (in_array('display_conditions', $denied, true)) $keys[] = '_conditions';
        if (in_array('style_presets', $denied, true)) $keys = array_merge($keys, ['_global_style', '_global_style_snapshot']);
        if (in_array('query_loop', $denied, true)) {
            $keys = array_merge($keys, self::BINDING_KEYS);
            if (($element['type'] ?? '') === 'list-dynamic') {
                $keys = array_merge($keys, ['template', 'pagination_mode']);
                $loopHost = true;
            }
            // 内容目录的卡片模板与动态列表同属循环模板：结构同样受保护
            if (($element['type'] ?? '') === 'content-catalog') {
                $loopHost = true;
            }
        }
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== [] && $value !== 'none') $fields[$key] = $value;
            unset($data[$key]);
        }
        if (in_array('table', $denied, true) && ($element['type'] ?? '') === 'table') {
            // 表格归属 yikai-builder：能力未放行时整张表冻结（不能新增、修改或删除），已发布内容照常渲染
            $fields['table'] = $data;
            $data = [];
        }
        $children = is_array($data['children'] ?? null) ? $data['children'] : [];
        if ($loopHost && $children !== []) {
            // 循环模板只冻结结构（顺序、ID、类型）；子元素的字段绑定逐个比较，普通文案与样式可改。
            $fields['children'] = array_map(
                static fn(mixed $child): array => is_array($child) ? [(string) ($child['id'] ?? ''), (string) ($child['type'] ?? '')] : ['', ''],
                array_values($children)
            );
        }
        if ($fields !== []) {
            if ($id === '' || $parent === '' || str_starts_with($parent, '/')) throw new RuntimeException(__('blox_protected_fields_changed'));
            ksort($fields);
            $protected['element:' . $id] = ['parent' => $parent, 'type' => $element['type'], 'fields' => $fields];
        }
        foreach ($children as $index => $child) {
            if (is_array($child)) {
                $data['children'][$index] = self::element($child, $id, $denied, $protected, $seen);
            }
        }
        if ($loopHost) {
            // 结构与绑定已由上面的投影比较；校验副本移除未变的模板子树，避免旧模板被整体判为新增。
            unset($data['children']);
        }
        $element['data'] = $data;
        return $element;
    }
}
