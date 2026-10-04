<?php
/**
 * Advanced Custom Fields（ACF 5/6）→ 本站扩展字段（2.0.4）。只做纯转换，读写库在 WordPressImporter。
 *
 * - 字段组：posts(post_type=acf-field-group)，挂载规则在 post_content（序列化）的 location 里——
 *   外层数组是「或」，内层是「且」。按规则落到归属：
 *     post_type==product → 产品；post_type==post → 内容；post_type==page → 栏目（单页导入为栏目）；
 *     taxonomy==product_cat → 产品分类；taxonomy==category → 栏目；options_page → 全站选项；
 *     post_taxonomy==product_cat:别名 / post_category==category:别名 → 产品 / 内容 + 挂载位置。
 * - 字段：posts(post_type=acf-field)，post_parent 指向字段组或父字段（重复器 / 组 / 灵活内容），
 *   post_excerpt = 字段名（meta_key），post_title = 显示名，post_content = 序列化设置（type、choices…）。
 * - 值：postmeta / termmeta / options 里「字段名」那一行（重复器子字段是 名_行号_子名，组是 名_子名）；
 *   图片 / 文件 / 相册存附件 id，关联存文章 id，日期存 Ymd。
 */

declare(strict_types=1);

final class WordPressAcf
{
    /** ACF 类型 → 本站类型；不在表里的（tab、message、accordion、clone、google_map、user…）跳过并记下。 */
    private const TYPE_MAP = [
        'text' => 'text', 'email' => 'text', 'url' => 'text', 'password' => 'text', 'oembed' => 'text', 'page_link' => 'text',
        'date_time_picker' => 'text', 'time_picker' => 'text',
        'textarea' => 'textarea', 'wysiwyg' => 'richtext',
        'number' => 'number', 'range' => 'number',
        'date_picker' => 'date', 'true_false' => 'switch',
        'select' => 'select', 'radio' => 'select', 'button_group' => 'select', 'checkbox' => 'multi_select',
        'image' => 'image', 'gallery' => 'images', 'file' => 'file',
        'link' => 'link', 'color_picker' => 'color',
        'repeater' => 'repeater', 'flexible_content' => 'repeater', 'group' => 'group',
        'relationship' => 'relationship', 'post_object' => 'relationship',
    ];
    /** 布局类字段：不存值，静默跳过 */
    private const LAYOUT_TYPES = ['tab', 'message', 'accordion'];

    /**
     * 字段组 + 字段 → 树。
     * @param list<array<string,mixed>> $groupPosts acf-field-group 行
     * @param list<array<string,mixed>> $fieldPosts acf-field 行
     * @return list<array{id:int,title:string,order:int,location:list<list<array<string,string>>>,fields:list<array<string,mixed>>}>
     */
    public static function groups(array $groupPosts, array $fieldPosts): array
    {
        $children = [];
        foreach ($fieldPosts as $post) {
            $children[(int) $post['post_parent']][] = $post;
        }
        $build = static function (int $parent) use (&$build, $children): array {
            $out = [];
            $rows = $children[$parent] ?? [];
            usort($rows, static fn (array $a, array $b): int => [(int) $a['menu_order'], (int) $a['ID']] <=> [(int) $b['menu_order'], (int) $b['ID']]);
            foreach ($rows as $post) {
                $settings = WordPressSource::unserializeArray((string) $post['post_content']);
                $out[] = [
                    'id' => (int) $post['ID'],
                    'name' => (string) $post['post_excerpt'],
                    'label' => html_entity_decode((string) $post['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'key' => (string) $post['post_name'],
                    'order' => (int) $post['menu_order'],
                    'settings' => $settings,
                    'children' => $build((int) $post['ID']),
                ];
            }
            return $out;
        };
        $groups = [];
        foreach ($groupPosts as $post) {
            $settings = WordPressSource::unserializeArray((string) $post['post_content']);
            $groups[] = [
                'id' => (int) $post['ID'],
                'title' => html_entity_decode((string) $post['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'order' => (int) ($settings['menu_order'] ?? $post['menu_order'] ?? 0),
                'location' => self::locationRules($settings['location'] ?? []),
                'fields' => $build((int) $post['ID']),
            ];
        }
        usort($groups, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        return $groups;
    }

    /** @return list<list<array<string,string>>> */
    private static function locationRules(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $and) {
            $rules = [];
            foreach (is_array($and) ? $and : [] as $rule) {
                if (is_array($rule)) {
                    $rules[] = ['param' => (string) ($rule['param'] ?? ''), 'operator' => (string) ($rule['operator'] ?? '=='), 'value' => (string) ($rule['value'] ?? '')];
                }
            }
            if ($rules !== []) {
                $out[] = $rules;
            }
        }
        return $out;
    }

    /**
     * 挂载规则 → 归属列表（同一组可挂多处）。每项 [归属, 位置分类（taxonomy => [别名…]）]；认不出的规则返回在 $unknown 里。
     * @param list<list<array<string,string>>> $location
     * @param list<string> $unknown
     * @return list<array{owner:string,terms:array<string,list<string>>}>
     */
    public static function owners(array $location, array &$unknown = []): array
    {
        $out = [];
        foreach ($location as $and) {
            $owner = null;
            $terms = [];
            $ok = true;
            foreach ($and as $rule) {
                if ($rule['operator'] !== '==') {
                    $ok = false;
                    $unknown[] = $rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value'];
                    continue;
                }
                $mapped = match ($rule['param']) {
                    'post_type' => ['product' => 'product', 'post' => 'content', 'page' => 'channel'][$rule['value']] ?? null,
                    'taxonomy' => ['product_cat' => 'product_category', 'category' => 'channel'][$rule['value']] ?? null,
                    'options_page' => 'site',
                    'post_taxonomy' => str_starts_with($rule['value'], 'product_cat:') ? 'product' : (str_starts_with($rule['value'], 'category:') ? 'content' : null),
                    'post_category' => 'content',
                    'page_template', 'page_type', 'page', 'page_parent', 'post_status', 'post_format', 'post', 'post_template' => 'ignore',
                    default => null,
                };
                if ($mapped === null) {
                    $ok = false;
                    $unknown[] = $rule['param'] . ' == ' . $rule['value'];
                    continue;
                }
                if ($mapped === 'ignore') {
                    continue;   // 更细的页面条件：字段照样导入，在整个归属上显示
                }
                if ($rule['param'] === 'post_taxonomy' || $rule['param'] === 'post_category') {
                    [$taxonomy, $slug] = array_pad(explode(':', $rule['value'], 2), 2, '');
                    $terms[$taxonomy === '' ? 'category' : $taxonomy][] = $slug !== '' ? $slug : $taxonomy;
                    $mapped = $taxonomy === 'product_cat' ? 'product' : 'content';
                }
                $owner ??= $mapped;
            }
            if ($ok && $owner !== null) {
                $out[] = ['owner' => $owner, 'terms' => $terms];
            }
        }
        return $out;
    }

    /** ACF 字段名 → 本站字段键（小写、连字符改下划线、数字开头补前缀）。 */
    public static function key(string $name): string
    {
        $key = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $name), '_'));
        if ($key === '' || !preg_match('/^[a-z]/', $key)) {
            $key = 'f_' . $key;
        }
        return substr($key, 0, 64);
    }

    /**
     * ACF 字段 → 本站字段定义 {field_type, options, placeholder, help_text, is_required, config}；跳过的返回 null 并写 $skipped。
     * @param array<string,mixed> $acf
     */
    public static function definition(array $acf, ?string &$skipped = null): ?array
    {
        $s = (array) $acf['settings'];
        $acfType = (string) ($s['type'] ?? 'text');
        if (in_array($acfType, self::LAYOUT_TYPES, true)) {
            $skipped = '';
            return null;
        }
        $type = self::TYPE_MAP[$acfType] ?? null;
        if ($type === null) {
            $skipped = $acfType;
            return null;
        }
        if ($acfType === 'select' && !empty($s['multiple'])) {
            $type = 'multi_select';
        }
        $def = [
            'field_type' => $type,
            'options' => self::choices($s['choices'] ?? []),
            'placeholder' => mb_substr((string) ($s['placeholder'] ?? ''), 0, 255),
            'help_text' => mb_substr(trim(strip_tags((string) ($s['instructions'] ?? ''))), 0, 255),
            'is_required' => !empty($s['required']) ? 1 : 0,
            'config' => [],
        ];
        if ($type === 'repeater' || $type === 'group') {
            $subs = [];
            $children = (array) $acf['children'];
            if ($acfType === 'flexible_content') {
                // 灵活内容：各布局的子字段合并成一张表，另加一列「布局」
                $subs[] = ['key' => 'acf_layout', 'name' => 'Layout', 'type' => 'text'];
            }
            foreach ($children as $child) {
                $sub = self::subDefinition($child);
                if ($sub !== null && !in_array($sub['key'], array_column($subs, 'key'), true)) {
                    $subs[] = $sub;
                }
            }
            $def['config']['sub_fields'] = $subs;
            if ($type === 'repeater') {
                if ((int) ($s['min'] ?? 0) > 0) $def['config']['min'] = (int) $s['min'];
                if ((int) ($s['max'] ?? 0) > 0) $def['config']['max'] = (int) $s['max'];
                if (trim((string) ($s['button_label'] ?? '')) !== '') $def['config']['button_label'] = (string) $s['button_label'];
            }
        }
        if ($type === 'relationship') {
            $postTypes = array_values(array_filter((array) ($s['post_type'] ?? [])));
            $def['config']['target'] = ($postTypes[0] ?? '') === 'product' ? 'product' : 'content';
            $max = $acfType === 'post_object' && empty($s['multiple']) ? 1 : (int) ($s['max'] ?? 0);
            if ($max > 0) $def['config']['max'] = $max;
        }
        return $def;
    }

    /** 重复器 / 组里的子字段：只收简单类型（富文本降为多行文本，相册取第一张，嵌套重复器跳过）。 */
    private static function subDefinition(array $acf): ?array
    {
        $acfType = (string) ($acf['settings']['type'] ?? 'text');
        $type = self::TYPE_MAP[$acfType] ?? null;
        $type = match ($type) {
            'richtext' => 'textarea',
            'images' => 'image',
            'multi_select' => 'text',
            'repeater', 'group', 'relationship', null => null,
            default => $type,
        };
        if ($type === null) {
            return null;
        }
        $sub = ['key' => self::key((string) $acf['name']), 'name' => mb_substr((string) $acf['label'], 0, 100), 'type' => $type];
        if ($type === 'select') {
            $sub['options'] = self::choices($acf['settings']['choices'] ?? []);
        }
        return $sub;
    }

    private static function choices(mixed $choices): string
    {
        $lines = [];
        foreach (is_array($choices) ? $choices : [] as $key => $label) {
            $lines[] = str_replace(["\r", "\n", '|'], ' ', (string) $key) . '|' . str_replace(["\r", "\n"], ' ', (string) $label);
        }
        return implode("\n", $lines);
    }

    /**
     * 读一个字段的值（尚未经 ExtFields::sanitize）。$meta 是该条目的全部 meta（或 options 前缀去掉后的表），
     * $prefix 用于重复器 / 组的子字段（名_行号_ / 名_）。
     * @param array<string,mixed> $acf
     * @param array<string,string> $meta
     * @param callable(string,int):string $resolve ('attachment', id) → 网址；('product'|'content', wp id) → 本站 id（字符串，0 为没有）
     */
    public static function value(array $acf, array $meta, string $prefix, callable $resolve): mixed
    {
        $s = (array) $acf['settings'];
        $acfType = (string) ($s['type'] ?? 'text');
        $name = $prefix . (string) $acf['name'];
        $raw = $meta[$name] ?? null;
        switch ($acfType) {
            case 'repeater':
            case 'flexible_content':
                $layouts = $acfType === 'flexible_content' ? WordPressSource::unserializeArray((string) $raw) : [];
                $count = $acfType === 'flexible_content' ? count($layouts) : (int) $raw;
                $rows = [];
                for ($i = 0; $i < min($count, ExtFields::MAX_ROWS); $i++) {
                    $row = $acfType === 'flexible_content' ? ['acf_layout' => (string) ($layouts[$i] ?? '')] : [];
                    foreach ((array) $acf['children'] as $child) {
                        $row[self::key((string) $child['name'])] = self::subValue($child, $meta, $name . '_' . $i . '_', $resolve);
                    }
                    $rows[] = $row;
                }
                return $rows;
            case 'group':
                $values = [];
                foreach ((array) $acf['children'] as $child) {
                    $values[self::key((string) $child['name'])] = self::subValue($child, $meta, $name . '_', $resolve);
                }
                return $values;
        }
        if ($raw === null || $raw === '') {
            return '';
        }
        $raw = (string) $raw;
        switch ($acfType) {
            case 'image':
            case 'file':
                return ctype_digit($raw) ? $resolve('attachment', (int) $raw) : $raw;
            case 'gallery':
                return implode(',', array_filter(array_map(static fn ($id): string => $resolve('attachment', (int) $id), WordPressSource::unserializeArray($raw))));
            case 'checkbox':
            case 'select':
                $list = str_starts_with($raw, 'a:') ? WordPressSource::unserializeArray($raw) : [$raw];
                return $acfType === 'checkbox' || !empty($s['multiple']) ? array_values(array_map('strval', $list)) : (string) ($list[0] ?? '');
            case 'true_false':
                return $raw === '1' ? '1' : '0';
            case 'date_picker':
                return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : $raw;
            case 'link':
                $link = str_starts_with($raw, 'a:') ? WordPressSource::unserializeArray($raw) : ['url' => $raw];
                return ['url' => (string) ($link['url'] ?? ''), 'title' => (string) ($link['title'] ?? ''), 'target' => (string) ($link['target'] ?? '')];
            case 'relationship':
            case 'post_object':
                $target = ((array) ($s['post_type'] ?? []))[0] ?? '' ;
                $kind = $target === 'product' ? 'product' : 'content';
                $ids = str_starts_with($raw, 'a:') ? WordPressSource::unserializeArray($raw) : [$raw];
                return implode(',', array_filter(array_map(static fn ($id): string => $resolve($kind, (int) $id), $ids), static fn (string $id): bool => $id !== '' && $id !== '0'));
        }
        return $raw;
    }

    /** 子字段的值：按子定义的降级规则（相册取第一张、多选连成文字）。 */
    private static function subValue(array $acf, array $meta, string $prefix, callable $resolve): string
    {
        $acfType = (string) ($acf['settings']['type'] ?? 'text');
        if (in_array($acfType, ['repeater', 'flexible_content', 'group', 'relationship', 'post_object'], true)) {
            return '';
        }
        $value = self::value($acf, $meta, $prefix, $resolve);
        if ($acfType === 'gallery') {
            return (string) (explode(',', (string) $value)[0] ?? '');
        }
        if (is_array($value)) {
            return $acfType === 'link' ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : implode(', ', array_map('strval', $value));
        }
        return (string) $value;
    }
}
