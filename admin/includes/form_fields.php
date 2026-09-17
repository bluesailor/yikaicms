<?php
/**
 * 表单提交详情：把 extra 里的自定义字段（预约日期、时段等）配上表单模板里的标签，供后台查看。
 * name / phone / email / company / content 有独立列，详情里已单独显示，这里跳过。
 */

declare(strict_types=1);

const FORM_SUBMISSION_CORE_KEYS = ['name', 'phone', 'email', 'company', 'content'];

/**
 * 从表单模板取「字段名 => 标签」：CF7 模板取紧挨在标签前的 <label>，旧 JSON 取 label。
 *
 * @return array<string,string>
 */
function formTemplateFieldLabels(string $fieldsRaw): array
{
    $decoded = json_decode($fieldsRaw, true);
    if (is_array($decoded) && formFieldsIsList($decoded)) {
        $labels = [];
        foreach ($decoded as $field) {
            $key = is_array($field) ? (string) ($field['key'] ?? $field['name'] ?? '') : '';
            if ($key !== '') {
                $labels[$key] = trim((string) ($field['label'] ?? ''));
            }
        }
        return $labels;
    }
    preg_match_all(
        '/<label\b[^>]*>(.*?)<\/label>\s*\[(?:text|email|tel|textarea|number|date|url|select|radio|checkbox)\*?\s+([a-zA-Z0-9_-]+)/is',
        $fieldsRaw,
        $matches,
        PREG_SET_ORDER
    );
    $labels = [];
    foreach ($matches as $match) {
        $text = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // 去掉必填星号；用 /u 正则而不是 rtrim——rtrim 按字节剥离会截坏多字节汉字
        $labels[$match[2]] = (string) preg_replace('/[\s*\x{3000}]+$/u', '', $text);
    }
    return $labels;
}

/** PHP 8.0 兼容的列表判断（array_is_list 自 8.1 起）。 */
function formFieldsIsList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/**
 * @param array<string,string> $labels
 * @return list<array{key:string,label:string,value:string}>
 */
function formSubmissionExtraFields(string $extraJson, array $labels): array
{
    $extra = json_decode($extraJson, true);
    if (!is_array($extra)) {
        return [];
    }
    $fields = [];
    foreach ($extra as $key => $value) {
        $key = (string) $key;
        if (in_array($key, FORM_SUBMISSION_CORE_KEYS, true) || !is_scalar($value)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $label = $labels[$key] ?? '';
        $fields[] = ['key' => $key, 'label' => $label !== '' ? $label : $key, 'value' => $value];
    }
    return $fields;
}
