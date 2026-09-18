<?php

declare(strict_types=1);

/** Promote a legacy FAQ reference into ordinary, independently editable content. */
final class HomeFaqContent
{
    public const KEY = '_home_faq_i18n';
    /** Editor-only markers: which visible fields are translations, so a save writes them back to that language. */
    public const EDIT_KEY = '_home_faq_edit';
    public const ITEM_EDIT_KEY = '_i18n';

    /** @param array<string,mixed> $block @param list<string>|null $languages @return array<string,mixed>|null */
    public static function toSection(array $block, string $prefix, ?array $languages = null): ?array
    {
        $type = $block['block_type'] ?? '';
        if (!is_string($type) || !preg_match('/^custom:([1-9][0-9]*)$/D', $type, $match)) {
            return null;
        }
        $key = 'home_custom_' . $match[1];
        $language = siteLang();
        $section = self::readSection($key, $block, $language);
        if ($section === null) {
            return null;
        }
        $structure = self::structure($section);
        $section['id'] = $prefix;
        $section['type'] = 'section';
        $section['name'] = (string) ($section['settings']['title'] ?? '') ?: __('blox_el_accordion');
        $section['columns'][0]['id'] = $prefix . '_column';
        $section['columns'][0]['elements'][0]['id'] = $prefix . '_faq';
        $binding = ['lang' => $language, 'source' => self::content($section), 'translations' => []];
        $languages ??= array_keys(function_exists('availableLanguages') ? availableLanguages() : []);
        foreach ($languages as $locale) {
            if ($locale === $language) {
                continue;
            }
            $translated = self::readSection($key, $block, $locale);
            if ($translated === null || self::structure($translated) !== $structure) {
                return null;
            }
            $binding['translations'][$locale] = self::content($translated);
        }
        // Keep translations in the document, not dependent on the old homepage settings.
        $section['columns'][0]['elements'][0]['data'][self::KEY] = $binding;
        if (array_key_exists('enabled', $block) && empty($block['enabled'])) {
            $section['settings']['hidden'] = true;
        }
        return $section;
    }

    /** Read-only localization; a manually edited field keeps its new value. @param array<string,mixed> $section @return array<string,mixed> */
    public static function localize(array $section, ?string $language = null, bool $forEditor = false): array
    {
        $language ??= siteLang();
        foreach ($section['columns'] ?? [] as $ci => $column) {
            foreach ($column['elements'] ?? [] as $ei => $element) {
                if (($element['type'] ?? '') !== 'accordion') {
                    continue;
                }
                $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                $binding = $data[self::KEY] ?? null;
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
                $items = $target['items'] ?? null;
                if (is_array($items) && is_array($binding['source']['items'] ?? null) && is_array($data['items'] ?? null)) {
                    $section['columns'][$ci]['elements'][$ei]['data']['items'] = self::localizeItems(
                        $data['items'], $binding['source']['items'], $items, $forEditor
                    );
                }
                if ($forEditor) {
                    $section['columns'][$ci]['elements'][$ei]['data'][self::EDIT_KEY] = ['lang' => $language, 'fields' => $fields];
                }
            }
        }
        return $section;
    }

    /** @param array<string,mixed> $block @return array<string,mixed>|null */
    private static function readSection(string $key, array $block, string $language): ?array
    {
        $raw = (string) config($key . '_' . $language, '');
        $custom = json_decode($raw !== '' ? $raw : (string) config($key, ''), true);
        $blocks = is_array($custom) && is_array($custom['blocks'] ?? null) ? $custom['blocks'] : [];
        $blocks = HomeBloxBlockSchema::applyCustomOverrides($blocks, $block, $language);
        // Never drop additional sections, columns or elements from a mixed custom block.
        if (count($blocks) !== 1 || !is_array($blocks[0] ?? null)) {
            return null;
        }
        $section = $blocks[0];
        $columns = $section['columns'] ?? [];
        if (!is_array($columns) || count($columns) !== 1 || !is_array($columns[0] ?? null)) {
            return null;
        }
        $elements = $columns[0]['elements'] ?? [];
        if (!is_array($elements) || count($elements) !== 1 || !is_array($elements[0] ?? null)
            || ($elements[0]['type'] ?? '') !== 'accordion') {
            return null;
        }
        $section['settings'] = is_array($section['settings'] ?? null) ? $section['settings'] : [];
        $data = is_array($elements[0]['data'] ?? null) ? $elements[0]['data'] : [];
        $items = (new AccordionElement())->items($data);
        if (count($items) > 30) {
            return null;
        }
        $data['items'] = array_map(
            static fn(array $item): array => ['question' => $item[0], 'answer' => $item[1]]
                + (($item[2] ?? '') === 'html' ? ['answer_format' => 'html'] : []),
            $items
        );
        $section['columns'][0]['elements'][0]['data'] = $data;
        foreach (['title', 'subtitle'] as $field) {
            if (array_key_exists('custom_' . $field, $block)) {
                $section['settings'][$field] = (string) $block['custom_' . $field];
            }
        }
        return $section;
    }

    /** Content may differ by language, but a conversion must not erase localized layouts or behavior. @param array<string,mixed> $section @return array<string,mixed> */
    private static function structure(array $section): array
    {
        unset($section['id'], $section['name'], $section['settings']['title'], $section['settings']['subtitle']);
        unset($section['columns'][0]['id'], $section['columns'][0]['elements'][0]['id']);
        unset($section['columns'][0]['elements'][0]['data']['items']);
        return $section;
    }

    /**
     * Homepage editor view in one language: the panel shows the same translated text as the canvas.
     * @param array<int,mixed> $sections @return array<int,mixed>
     */
    public static function forEditor(array $sections, string $language): array
    {
        foreach ($sections as $index => $section) {
            if (is_array($section)) {
                $sections[$index] = self::localize($section, $language, true);
            }
        }
        return $sections;
    }

    /**
     * Reverse forEditor() before saving: marked fields go back into their translation and the shared
     * document keeps the source-language text, so editing Japanese never overwrites Chinese.
     */
    public static function fromEditorJson(string $json): string
    {
        if (!str_contains($json, self::EDIT_KEY)) {
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
                $sections[$index] = self::fromEditorSection($section);
            }
        }
        if ($isList) {
            $decoded = $sections;
        } else {
            $decoded['sections'] = $sections;
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private static function fromEditorSection(array $section): array
    {
        foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $ci => $column) {
            foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $ei => $element) {
                if (!is_array($element) || !is_array($element['data'] ?? null)) {
                    continue;
                }
                $data = $element['data'];
                $edit = $data[self::EDIT_KEY] ?? null;
                unset($data[self::EDIT_KEY]);
                $binding = $data[self::KEY] ?? null;
                $language = is_array($edit) && is_string($edit['lang'] ?? null) ? $edit['lang'] : '';
                $valid = ($element['type'] ?? '') === 'accordion' && is_array($binding) && $language !== ''
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
                    // A duplicated row keeps its translated text as ordinary content instead of claiming the same translation.
                    if ($valid && is_int($k) && !isset($seen[$k]) && is_array($binding['source']['items'][$k] ?? null)
                        && is_array($target['items'][$k] ?? null)) {
                        $seen[$k] = true;
                        $original = $binding['source']['items'][$k];
                        foreach (array_intersect(['question', 'answer'], (array) ($mark['f'] ?? [])) as $field) {
                            if (!is_string($original[$field] ?? null)) {
                                continue;
                            }
                            $target['items'][$k][$field] = (string) ($item[$field] ?? '');
                            $item[$field] = $original[$field];
                            if ($field === 'answer') {
                                if (($item['answer_format'] ?? '') === 'html') $target['items'][$k]['answer_format'] = 'html';
                                else unset($target['items'][$k]['answer_format']);
                                if (($original['answer_format'] ?? '') === 'html') $item['answer_format'] = 'html';
                                else unset($item['answer_format']);
                            }
                        }
                    }
                    $data['items'][$j] = $item;
                }
                if ($valid) {
                    $binding['translations'][$language] = $target;
                    $data[self::KEY] = $binding;
                }
                $section['columns'][$ci]['elements'][$ei]['data'] = $data;
            }
        }
        return $section;
    }

    /** @param array<int,mixed> $items @param array<int,mixed> $source @param array<int,mixed> $target @return array<int,mixed> */
    private static function localizeItems(array $items, array $source, array $target, bool $mark = false): array
    {
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
                $q = is_string($original['question'] ?? null) && $original['question'] !== ''
                    && ($item['question'] ?? null) === $original['question'];
                $a = is_string($original['answer'] ?? null) && $original['answer'] !== ''
                    && ($item['answer'] ?? null) === $original['answer']
                    && ($item['answer_format'] ?? '') === ($original['answer_format'] ?? '');
                if ($q && $a) {
                    $exact[] = $index;
                } elseif ($q || $a) {
                    $partial[] = $index;
                }
            }
            // Match content instead of row positions so reorder/delete operations retain the right translations.
            $matches = $exact !== [] ? $exact : $partial;
            if (count($matches) !== 1) {
                continue;
            }
            $index = $matches[0];
            if (!is_array($target[$index] ?? null)) {
                continue;
            }
            $fields = [];
            foreach (['question', 'answer'] as $field) {
                if ($field === 'answer' && ($item['answer_format'] ?? '') !== ($source[$index]['answer_format'] ?? '')) {
                    continue;
                }
                if (is_string($target[$index][$field] ?? null) && is_string($source[$index][$field] ?? null)
                    && ($item[$field] ?? null) === $source[$index][$field]) {
                    $item[$field] = $target[$index][$field];
                    $fields[] = $field;
                    if ($field === 'answer') {
                        if (($target[$index]['answer_format'] ?? '') === 'html') $item['answer_format'] = 'html';
                        else unset($item['answer_format']);
                    }
                }
            }
            if ($mark && $fields !== []) {
                $item[self::ITEM_EDIT_KEY] = ['s' => $index, 'f' => $fields];
            }
        }
        unset($item);
        return $items;
    }

    /** @param array<string,mixed> $section @return array<string,mixed> */
    private static function content(array $section): array
    {
        return [
            'title' => (string) ($section['settings']['title'] ?? ''),
            'subtitle' => (string) ($section['settings']['subtitle'] ?? ''),
            'items' => $section['columns'][0]['elements'][0]['data']['items'] ?? [],
        ];
    }
}
