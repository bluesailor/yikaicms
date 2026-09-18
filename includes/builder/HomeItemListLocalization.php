<?php

declare(strict_types=1);

/**
 * 首页「区块标题 + 副标题 + 条目列表」一类普通元素的多语言绑定（通用版）。
 *
 * 首页只有一份 Blox 文档，从区块模板插进来的普通元素天然只有一种语言——英文、日文首页
 * 照样显示中文（2026-09-18 客户评价轮播即如此）。做法与 HomeFaqContent 相同：
 *
 *   元素 data[KEY] = {
 *     lang:         源语言（文档里存的就是这种语言的文字），
 *     source:       {title, subtitle, items}   写绑定那一刻的源文案，
 *     translations: {en: {...}, ja: {...}}     各语言的对应文案
 *   }
 *
 * 渲染时只替换「当前值仍等于源文案」的字段：站长在源语言里手动改过的文字保持原样，
 * 不会被旧译文盖掉。条目按内容而不是位置匹配，排序、删除之后译文不会串到别的条目上。
 * 编辑器里切到某个语言编辑时，被替换的字段带上标记，保存前（fromEditorJson）把改动写回
 * 该语言的译文，文档里仍保留源语言文字——改日文不会覆盖中文。
 *
 * 子类只需声明：绑定键、编辑标记键、元素类型、条目里要翻译的文字字段。
 */
abstract class HomeItemListLocalization
{
    /** 条目上的编辑期标记：{s: 源条目下标, f: 被替换的字段}，保存前移除 */
    public const ITEM_EDIT_KEY = '_i18n';

    abstract protected static function bindingKey(): string;

    abstract protected static function editKey(): string;

    abstract protected static function elementType(): string;

    /** @return list<string> */
    abstract protected static function itemFields(): array;

    /**
     * 按语言替换文案（只读）。$forEditor 为真时给被替换的字段打标记，供保存时写回。
     *
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    public static function localize(array $section, ?string $language = null, bool $forEditor = false): array
    {
        $language ??= siteLang();
        foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $ci => $column) {
            foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $ei => $element) {
                if (!is_array($element) || ($element['type'] ?? '') !== static::elementType()) {
                    continue;
                }
                $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                $binding = $data[static::bindingKey()] ?? null;
                if (!is_array($binding) || ($binding['lang'] ?? '') === $language
                    || !is_array($binding['source'] ?? null) || !is_array($binding['translations'] ?? null)) {
                    continue;
                }
                $target = $binding['translations'][$language] ?? null;
                if (!is_array($target)) {
                    continue;
                }
                $fields = [];
                foreach (['title', 'subtitle'] as $field) {
                    if (is_string($target[$field] ?? null) && is_string($binding['source'][$field] ?? null)
                        && ($section['settings'][$field] ?? '') === $binding['source'][$field]) {
                        $section['settings'][$field] = $target[$field];
                        $fields[] = $field;
                    }
                }
                if (is_array($target['items'] ?? null) && is_array($binding['source']['items'] ?? null)
                    && is_array($data['items'] ?? null)) {
                    $section['columns'][$ci]['elements'][$ei]['data']['items'] = static::localizeItems(
                        $data['items'],
                        $binding['source']['items'],
                        $target['items'],
                        $forEditor
                    );
                }
                if ($forEditor) {
                    $section['columns'][$ci]['elements'][$ei]['data'][static::editKey()] = ['lang' => $language, 'fields' => $fields];
                }
            }
        }
        return $section;
    }

    /**
     * 首页编辑器按当前语言打开：面板里显示的文字与画布一致。
     *
     * @param array<int,mixed> $sections
     * @return array<int,mixed>
     */
    public static function forEditor(array $sections, string $language): array
    {
        foreach ($sections as $index => $section) {
            if (is_array($section)) {
                $sections[$index] = static::localize($section, $language, true);
            }
        }
        return $sections;
    }

    /** 保存前反转 forEditor()：打过标记的字段写回对应语言的译文，文档保留源语言文字。 */
    public static function fromEditorJson(string $json): string
    {
        if (!str_contains($json, static::editKey())) {
            return $json;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        $isList = BloxDocumentPipeline::isList($decoded);
        $sections = $isList ? $decoded : ($decoded['sections'] ?? null);
        if (!is_array($sections)) {
            return $json;
        }
        foreach ($sections as $index => $section) {
            if (is_array($section)) {
                $sections[$index] = static::fromEditorSection($section);
            }
        }
        if ($isList) {
            $decoded = $sections;
        } else {
            $decoded['sections'] = $sections;
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    protected static function fromEditorSection(array $section): array
    {
        foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $ci => $column) {
            foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $ei => $element) {
                // 只动自己这种元素：别的元素（如常见问题）的条目也用 _i18n 做标记，由各自的类处理
                if (!is_array($element) || ($element['type'] ?? '') !== static::elementType()
                    || !is_array($element['data'] ?? null)) {
                    continue;
                }
                $data = $element['data'];
                $edit = $data[static::editKey()] ?? null;
                unset($data[static::editKey()]);
                $binding = $data[static::bindingKey()] ?? null;
                $language = is_array($edit) && is_string($edit['lang'] ?? null) ? $edit['lang'] : '';
                $valid = ($element['type'] ?? '') === static::elementType() && is_array($binding) && $language !== ''
                    && $language !== ($binding['lang'] ?? '') && is_array($binding['source'] ?? null)
                    && is_array($binding['translations'][$language] ?? null);
                $target = $valid ? $binding['translations'][$language] : [];
                if ($valid) {
                    foreach (['title', 'subtitle'] as $field) {
                        if (in_array($field, (array) ($edit['fields'] ?? []), true) && is_string($binding['source'][$field] ?? null)
                            && is_string($section['settings'][$field] ?? null)) {
                            $target[$field] = $section['settings'][$field];
                            $section['settings'][$field] = $binding['source'][$field];
                        }
                    }
                }
                $seen = [];
                foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $j => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $mark = $item[self::ITEM_EDIT_KEY] ?? null;
                    unset($item[self::ITEM_EDIT_KEY]);
                    $k = is_array($mark) ? ($mark['s'] ?? null) : null;
                    // 复制出来的条目保留译文作为普通内容，不再认领同一条译文
                    if ($valid && is_int($k) && !isset($seen[$k]) && is_array($binding['source']['items'][$k] ?? null)
                        && is_array($target['items'][$k] ?? null)) {
                        $seen[$k] = true;
                        $original = $binding['source']['items'][$k];
                        foreach (array_intersect(static::itemFields(), (array) ($mark['f'] ?? [])) as $field) {
                            if (!is_string($original[$field] ?? null)) {
                                continue;
                            }
                            $target['items'][$k][$field] = (string) ($item[$field] ?? '');
                            $item[$field] = $original[$field];
                        }
                    }
                    $data['items'][$j] = $item;
                }
                if ($valid) {
                    $binding['translations'][$language] = $target;
                    $data[static::bindingKey()] = $binding;
                }
                $section['columns'][$ci]['elements'][$ei]['data'] = $data;
            }
        }
        return $section;
    }

    /**
     * 条目按内容匹配源条目：所有有值字段都相同为精确匹配，只有部分相同为部分匹配；
     * 恰好命中一条才替换，匹配不上或有歧义就保持原样（宁可不翻，也不错翻）。
     *
     * @param array<int,mixed> $items
     * @param array<int,mixed> $source
     * @param array<int,mixed> $target
     * @return array<int,mixed>
     */
    protected static function localizeItems(array $items, array $source, array $target, bool $mark): array
    {
        $fieldsToTranslate = static::itemFields();
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $exact = [];
            $partial = [];
            foreach ($source as $index => $original) {
                if (!is_array($original)) {
                    continue;
                }
                $same = 0;
                $compared = 0;
                foreach ($fieldsToTranslate as $field) {
                    if (!is_string($original[$field] ?? null) || $original[$field] === '') {
                        continue;
                    }
                    $compared++;
                    if (($item[$field] ?? null) === $original[$field]) {
                        $same++;
                    }
                }
                if ($compared > 0 && $same === $compared) {
                    $exact[] = $index;
                } elseif ($same > 0) {
                    $partial[] = $index;
                }
            }
            $matches = $exact !== [] ? $exact : $partial;
            if (count($matches) !== 1 || !is_array($target[$matches[0]] ?? null)) {
                continue;
            }
            $index = $matches[0];
            $replaced = [];
            foreach ($fieldsToTranslate as $field) {
                if (is_string($target[$index][$field] ?? null) && is_string($source[$index][$field] ?? null)
                    && ($item[$field] ?? null) === $source[$index][$field]) {
                    $item[$field] = $target[$index][$field];
                    $replaced[] = $field;
                }
            }
            if ($mark && $replaced !== []) {
                $item[self::ITEM_EDIT_KEY] = ['s' => $index, 'f' => $replaced];
            }
        }
        unset($item);
        return $items;
    }
}
