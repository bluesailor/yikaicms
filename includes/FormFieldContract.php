<?php
declare(strict_types=1);

/** 表单字段在后台、渲染与提交端共用的规范化边界。 */
final class FormFieldContract
{
    /** @return list<string> */
    public static function options(mixed $options): array
    {
        if (is_string($options)) $options = explode(',', $options);
        if (!is_array($options)) return [];
        $normalized = [];
        $seen = [];
        foreach ($options as $option) {
            if (!is_scalar($option)) continue;
            $value = trim((string) $option);
            if ($value === '' || isset($seen[$value])) continue;
            $seen[$value] = true;
            $normalized[] = $value;
        }
        return $normalized;
    }

    public static function choiceValue(string $type, mixed $raw, mixed $options): string
    {
        $allowed = self::options($options);
        if ($allowed === []) throw new InvalidArgumentException('Invalid choices');
        if ($type === 'checkbox') {
            $values = is_array($raw) ? $raw : [$raw];
            if (count($values) > 50) throw new InvalidArgumentException('Invalid choices');
            $selected = [];
            foreach ($values as $value) {
                if (!is_string($value)) throw new InvalidArgumentException('Invalid choice');
                $value = trim($value);
                if ($value === '' || !in_array($value, $allowed, true) || isset($selected[$value])) {
                    throw new InvalidArgumentException('Invalid choice');
                }
                $selected[$value] = true;
            }
            // 使用模板顺序规范化，不能靠调换 checkbox 顺序绕过去重指纹。
            return implode(', ', array_values(array_filter(
                $allowed,
                static fn(string $value): bool => isset($selected[$value])
            )));
        }
        if (!is_string($raw)) throw new InvalidArgumentException('Invalid choice');
        $value = trim($raw);
        if ($value !== '' && !in_array($value, $allowed, true)) throw new InvalidArgumentException('Invalid choice');
        return $value;
    }

    /** Hidden campaign values affect persistence/fingerprints, but are configuration rather than visitor content. */
    public static function contentForSpamScan(array $fields, array $values): string
    {
        $parts = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? 'text') === 'hidden') continue;
            $name = (string) ($field['name'] ?? $field['key'] ?? '');
            if ($name !== '' && isset($values[$name]) && is_string($values[$name])) $parts[] = $values[$name];
        }
        return implode("\n", $parts);
    }
}
