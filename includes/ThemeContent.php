<?php
declare(strict_types=1);
require_once __DIR__ . '/UrlPolicy.php';

/** Small, schema-driven content fields. Layouts continue to use the existing page builder. */
final class ThemeContent
{
    public static function schema(string $theme, ?string $themesRoot = null): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $theme)) throw new RuntimeException('tc_schema');
        $path = ($themesRoot ?? ROOT_PATH . '/themes') . '/' . $theme . '/content-fields.json';
        if (!is_file($path)) return [];
        if (is_link($path) || filesize($path) > 65536) throw new RuntimeException('tc_schema');
        $schema = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($schema) || ($schema['version'] ?? 0) !== 1 || !is_array($schema['fields'] ?? null) || count($schema['fields']) > 40) throw new RuntimeException('tc_schema');
        $fields = [];
        foreach ($schema['fields'] as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $field['key'])
                || isset($fields[$field['key']]) || !in_array($field['type'] ?? '', ['text', 'textarea', 'image', 'url', 'toggle'], true)) throw new RuntimeException('tc_schema');
            foreach (['label', 'hint', 'default'] as $name) {
                $value = $field[$name] ?? '';
                if (!is_string($value) && !is_array($value)) throw new RuntimeException('tc_schema');
                if (is_array($value)) foreach ($value as $lang => $text) {
                    if (!in_array($lang, ['zh-CN', 'en', 'ja'], true) || !is_string($text)) throw new RuntimeException('tc_schema');
                }
            }
            if (empty($field['label'])) throw new RuntimeException('tc_schema');
            $fields[$field['key']] = $field;
        }
        return $fields;
    }

    public static function localized(mixed $value, string $language): string
    {
        return is_array($value) ? (string) ($value[$language] ?? $value['zh-CN'] ?? '') : (string) $value;
    }

    public static function normalize(array $field, mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) throw new RuntimeException('tc_value');
        $value = trim((string) $value);
        if (strlen($value) > ($field['type'] === 'textarea' ? 20000 : 4000)) throw new RuntimeException('tc_value');
        if ($field['type'] === 'toggle') return $value === '1' ? '1' : '0';
        if ($field['type'] === 'url' || $field['type'] === 'image') {
            $safe = $field['type'] === 'url' ? UrlPolicy::href($value) : UrlPolicy::image($value);
            if ($value !== '' && $safe === '') throw new RuntimeException('tc_url');
            return $safe;
        }
        return $value;
    }

    public static function values(string $theme, string $language, array $fields): array
    {
        $all = json_decode((string) config('theme_content_' . $theme, ''), true);
        $saved = is_array($all[$language] ?? null) ? $all[$language] : [];
        $values = [];
        foreach ($fields as $key => $field) {
            try { $values[$key] = self::normalize($field, $saved[$key] ?? self::localized($field['default'] ?? '', $language)); }
            catch (RuntimeException $error) { $values[$key] = ''; }
        }
        return $values;
    }

    public static function fingerprint(string $theme, array $fields): string
    {
        return hash('sha256', serialize([$theme, $fields, settingModel()->get('theme_content_' . $theme, '')]));
    }

    public static function save(string $theme, string $language, array $input, string $expected): void
    {
        if (!in_array($language, ['zh-CN', 'en', 'ja'], true)) throw new RuntimeException('tc_value');
        $key = 'theme_content_' . $theme;
        $fields = self::schema($theme);
        if ($fields === [] || array_diff(array_keys($input), array_keys($fields))) throw new RuntimeException('tc_schema');
        if (function_exists('configOverrides') && array_key_exists($key, configOverrides())) throw new RuntimeException('tc_locked');
        db()->beginTransaction();
        try {
            if (!db()->isSqlite()) db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ? FOR UPDATE', [$key]);
            settingModel()->clearCache();
            if ((string) config('current_theme', 'default') !== $theme || !hash_equals(self::fingerprint($theme, $fields), $expected)) throw new RuntimeException('tc_stale');
            $all = json_decode((string) settingModel()->get($key, ''), true);
            $all = is_array($all) ? $all : [];
            $all[$language] = [];
            foreach ($fields as $fieldKey => $field) $all[$language][$fieldKey] = self::normalize($field, $input[$fieldKey] ?? '');
            settingModel()->set($key, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'theme');
            db()->commit();
        } catch (Throwable $error) {
            if (db()->getPdo()->inTransaction()) db()->rollback();
            settingModel()->clearCache();
            throw $error;
        }
    }
}
