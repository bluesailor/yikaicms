<?php
/**
 * HomeBloxBlockSchema 的 自定义覆盖层：按语言的 custom overrides 读写、嵌套路径写入、手风琴项解析与 HTML/URL 清洗。
 *
 * v1.18.6 拆分自 1807 行单文件（审计连续两轮点名的复杂度热点）。
 * trait 形态 = 类名/方法签名/调用方零改动的纯文件级拆分；方法体逐字节搬运，
 * 行为由 HomeBloxBlockSchemaTest 等黄金测试锁定。
 */

declare(strict_types=1);

trait HomeBloxCustomOverridesTrait
{
    public static function customLocaleKey(?string $locale = null): string
    {
        $locale = $locale ?? (function_exists('siteLang') ? siteLang() : 'zh-CN');
        return preg_replace('/[^a-zA-Z0-9_]+/', '_', str_replace('-', '_', $locale)) ?: 'default';
    }

    /** @param array<int,mixed> $blocks @param array<string,mixed> $data @return array<int,mixed> */
    public static function applyCustomOverrides(array $blocks, array $data, ?string $locale = null): array
    {
        $overrides = $data['custom_overrides'][self::customLocaleKey($locale)] ?? null;
        if (!is_array($overrides)) {
            return $blocks;
        }
        foreach ($blocks as $sectionIndex => &$section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            $sectionOverride = $overrides[$sectionIndex] ?? null;
            if (is_array($sectionOverride)
                && ($sectionOverride['columns_mode'] ?? '') === 'custom'
                && is_array($sectionOverride['columns'] ?? null)) {
                $section['columns'] = array_values($sectionOverride['columns']);
            }
            foreach ($section['columns'] as $columnIndex => &$column) {
                if (!is_array($column)) {
                    continue;
                }
                $columnOverride = $overrides[$sectionIndex]['columns'][$columnIndex] ?? null;
                if (!is_array($columnOverride)) {
                    continue;
                }
                if (array_key_exists('card_bg', $columnOverride)) {
                    $column['card_bg'] = (string) $columnOverride['card_bg'];
                }
                if (!is_array($column['elements'] ?? null)) {
                    continue;
                }
                foreach ($column['elements'] as $elementIndex => &$element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $value = $columnOverride['elements'][$elementIndex]['data'] ?? null;
                    if (!is_array($value)) {
                        continue;
                    }
                    $element['data'] = is_array($element['data'] ?? null) ? $element['data'] : [];
                    $allowed = match ((string) ($element['type'] ?? '')) {
                        'heading' => ['text'],
                        'text' => ['html'],
                        'button' => ['text', 'url'],
                        default => [],
                    };
                    foreach ($allowed as $key) {
                        if (array_key_exists($key, $value)) {
                            $element['data'][$key] = (string) $value[$key];
                        }
                    }
                    if (($element['type'] ?? '') === 'accordion'
                        && ($value['accordion_mode'] ?? '') === 'custom') {
                        $items = [];
                        foreach (array_slice((array) ($value['accordion_items'] ?? []), 0, 30) as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $question = self::sanitizeAccordionPart((string) ($item['question'] ?? ''), 500, true);
                            $answer = self::sanitizeAccordionPart((string) ($item['answer'] ?? ''), 5000);
                            $items[] = ['question' => $question, 'answer' => $answer];
                        }
                        $element['data']['items'] = $items;
                    } elseif (($element['type'] ?? '') === 'accordion'
                        && is_array($value['accordion_items'] ?? null)) {
                        $items = self::parseAccordionItems($element['data']['items'] ?? '');
                        foreach (array_slice($value['accordion_items'], 0, 30, true) as $itemIndex => $itemOverride) {
                            if (!is_numeric($itemIndex) || !isset($items[(int) $itemIndex]) || !is_array($itemOverride)) {
                                continue;
                            }
                            foreach (['question', 'answer'] as $field) {
                                if (array_key_exists($field, $itemOverride)) {
                                    $items[(int) $itemIndex][$field] = self::sanitizeAccordionPart(
                                        (string) $itemOverride[$field],
                                        $field === 'question' ? 500 : 5000,
                                        $field === 'question'
                                    );
                                }
                            }
                        }
                        $element['data']['items'] = $items;
                    }
                }
                unset($element);
            }
            unset($column);
        }
        unset($section);
        return $blocks;
    }

    /** @param array<string,mixed> $target */
    private static function setNestedValue(array &$target, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $cursor = &$target;
        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $cursor[$part] = $value;
                break;
            }
            if (!is_array($cursor[$part] ?? null)) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
        unset($cursor);
    }

    /** @return list<array{question:string,answer:string}> */
    private static function parseAccordionItems(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            foreach (array_slice($value, 0, 30) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $question = self::sanitizeAccordionPart((string) ($item['question'] ?? ''), 500, true);
                if ($question !== '') {
                    $items[] = [
                        'question' => $question,
                        'answer' => self::sanitizeAccordionPart((string) ($item['answer'] ?? ''), 5000),
                    ];
                }
            }
            return $items;
        }
        foreach (preg_split('/\r\n|\r|\n/', (string) $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$question, $answer] = array_pad(explode('|', $line, 2), 2, '');
            $question = self::sanitizeAccordionPart($question, 500, true);
            if ($question !== '') {
                $items[] = [
                    'question' => $question,
                    'answer' => self::sanitizeAccordionPart($answer, 5000),
                ];
            }
        }
        return $items;
    }

    private static function sanitizeAccordionPart(string $value, int $maxLength, bool $singleLine = false): string
    {
        $value = trim(strip_tags($value));
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if ($singleLine) {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        } else {
            $value = (string) preg_replace('/[^\S\n]{2,}/u', ' ', $value);
            $value = (string) preg_replace('/\n{3,}/u', "\n\n", $value);
        }
        return mb_substr($value, 0, $maxLength);
    }


    /**
     * @return list<array{icon:string,number:string,label:string}>
     * @psalm-suppress PossiblyUnusedMethod 调用方在付费 blox_editor.php（CI 公开扫描不含该文件）。
     */
    private static function normalizeCustomOverrides(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        foreach (array_slice($value, 0, 10, true) as $locale => $sections) {
            $localeKey = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $locale) ?: '';
            if ($localeKey === '' || !is_array($sections)) {
                continue;
            }
            foreach (array_slice($sections, 0, 10, true) as $sectionIndex => $section) {
                if (!is_numeric($sectionIndex) || !is_array($section)) {
                    continue;
                }
                $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
                if (($section['columns_mode'] ?? '') === 'custom') {
                    $sectionPrefix = $localeKey . '.' . (int) $sectionIndex . '.';
                    self::setNestedValue($clean, $sectionPrefix . 'columns_mode', 'custom');
                    self::setNestedValue(
                        $clean,
                        $sectionPrefix . 'columns',
                        self::normalizeStructuralColumns($columns)
                    );
                    continue;
                }
                foreach (array_slice($columns, 0, 12, true) as $columnIndex => $column) {
                    if (!is_numeric($columnIndex) || !is_array($column)) {
                        continue;
                    }
                    $prefix = $localeKey . '.' . (int) $sectionIndex . '.columns.' . (int) $columnIndex;
                    if (array_key_exists('card_bg', $column)) {
                        self::setNestedValue(
                            $clean,
                            $prefix . '.card_bg',
                            AbstractElement::cssColor($column['card_bg']) ?? ''
                        );
                    }
                    $elements = is_array($column['elements'] ?? null) ? $column['elements'] : [];
                    foreach (array_slice($elements, 0, 50, true) as $elementIndex => $element) {
                        if (!is_numeric($elementIndex) || !is_array($element)) {
                            continue;
                        }
                        $elementData = is_array($element['data'] ?? null) ? $element['data'] : [];
                        $elementPrefix = $prefix . '.elements.' . (int) $elementIndex . '.data.';
                        foreach (['text' => 500, 'url' => 1000] as $key => $maxLength) {
                            if (!array_key_exists($key, $elementData)) {
                                continue;
                            }
                            $raw = (string) $elementData[$key];
                            $sanitized = $key === 'url'
                                ? self::safeUrl($raw, true)
                                : mb_substr(trim(strip_tags($raw)), 0, $maxLength);
                            self::setNestedValue($clean, $elementPrefix . $key, $sanitized);
                        }
                        if (array_key_exists('html', $elementData)) {
                            $html = substr((string) $elementData['html'], 0, 50_000);
                            self::setNestedValue($clean, $elementPrefix . 'html', self::sanitizeCustomHtml($html));
                        }
                        $accordionMode = ($elementData['accordion_mode'] ?? '') === 'custom';
                        $accordionItems = is_array($elementData['accordion_items'] ?? null)
                            ? $elementData['accordion_items'] : [];
                        if ($accordionMode) {
                            self::setNestedValue($clean, $elementPrefix . 'accordion_mode', 'custom');
                            self::setNestedValue($clean, $elementPrefix . 'accordion_items', []);
                        }
                        $itemSource = $accordionMode
                            ? array_values(array_slice($accordionItems, 0, 30, true))
                            : array_slice($accordionItems, 0, 30, true);
                        foreach ($itemSource as $itemIndex => $item) {
                            if (!is_numeric($itemIndex) || !is_array($item)) {
                                continue;
                            }
                            foreach (['question' => 500, 'answer' => 5000] as $key => $maxLength) {
                                if (!array_key_exists($key, $item)) {
                                    continue;
                                }
                                self::setNestedValue(
                                    $clean,
                                    $elementPrefix . 'accordion_items.' . (int) $itemIndex . '.' . $key,
                                    self::sanitizeAccordionPart(
                                        (string) $item[$key],
                                        $maxLength,
                                        $key === 'question'
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }
        return $clean;
    }

    private static function sanitizeCustomHtml(string $html): string
    {
        if (function_exists('sanitizeHtml')) {
            return sanitizeHtml($html);
        }
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = strip_tags($html, '<p><br><b><i><u><s><em><strong><small><sub><sup><h1><h2><h3><h4><h5><h6><ul><ol><li><a><div><span>');
        $html = (string) preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);
        return (string) preg_replace('/(?:href|src)\s*=\s*["\']?\s*javascript\s*:/i', 'data-removed="1"', $html);
    }

    private static function safeUrl(string $value, bool $allowActionSchemes): string
    {
        $value = trim(strip_tags($value));
        if ($value === '' || mb_strlen($value) > 1000) {
            return '';
        }
        if (str_starts_with($value, '/') || str_starts_with($value, '#')
            || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        if ($allowActionSchemes && preg_match('#^(?:mailto|tel):#i', $value) === 1) {
            return $value;
        }
        return '';
    }
}
