<?php
declare(strict_types=1);
require_once __DIR__ . '/i18n/LanguageRegistry.php';
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
                    if (!LanguageRegistry::has((string) $lang) || !is_string($text)) throw new RuntimeException('tc_schema');
                }
            }
            if (empty($field['label'])) throw new RuntimeException('tc_schema');
            // 可选 area：字段归属的位置。home:<首页区块类型> 让它作为该区块的 Blox 控件出现
            // （channel 覆盖所有栏目区块）；header / footer 归主题页头页尾。缺省为全站文案。
            if (array_key_exists('area', $field)
                && (!is_string($field['area']) || preg_match(self::AREA_PATTERN, $field['area']) !== 1)) throw new RuntimeException('tc_schema');
            $fields[$field['key']] = $field;
        }
        return $fields;
    }

    public const AREA_PATTERN = '/^(?:home:[a-z][a-z_]{0,39}|header|footer)$/D';

    /**
     * 当前主题声明的字段（按请求缓存）；声明缺失或非法时返回空，不影响页面渲染。
     * @return array<string,array<string,mixed>>
     */
    public static function currentFields(): array
    {
        static $cache = [];
        $theme = function_exists('currentTheme') ? currentTheme() : (string) config('current_theme', 'default');
        if (!array_key_exists($theme, $cache)) {
            try { $cache[$theme] = self::schema($theme); }
            catch (Throwable $error) { $cache[$theme] = []; }
        }
        return $cache[$theme];
    }

    /**
     * 归属某首页区块类型的字段。channel:12 这类栏目区块统一匹配 home:channel。
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    public static function homeBlockFields(array $fields, string $blockType): array
    {
        $type = str_starts_with($blockType, 'channel:') ? 'channel' : $blockType;
        return array_filter($fields, static fn (array $field): bool => ($field['area'] ?? '') === 'home:' . $type);
    }

    /** 嵌套多语值按注册表的回落顺序取（韩语等缺译先显示英文，再回落中文）。 */
    public static function localized(mixed $value, string $language): string
    {
        return is_array($value) ? LanguageRegistry::localizedValue($value, $language) : (string) $value;
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

    /**
     * Blox 编辑器「页头/页尾主题内容」表单的状态：只含 header/footer 区的字段。
     * 标签按后台语言，值与默认文字按内容语言。
     * @return array{fields:list<array<string,string>>,values:array<string,string>,fingerprint:string,language:string}
     */
    public static function editorState(string $theme, string $language, string $adminLanguage): array
    {
        $fields = [];
        try { $schema = self::schema($theme); } catch (Throwable $error) { $schema = []; }
        foreach ($schema as $key => $field) {
            if (!in_array($field['area'] ?? '', ['header', 'footer'], true)) continue;
            $fields[] = [
                'key' => $key,
                'type' => (string) $field['type'],
                'area' => (string) $field['area'],
                'label' => self::localized($field['label'], $adminLanguage),
                'hint' => self::localized($field['hint'] ?? '', $adminLanguage),
                'default' => self::localized($field['default'] ?? '', $language),
            ];
        }
        $areaSchema = array_intersect_key($schema, array_flip(array_column($fields, 'key')));
        return [
            'fields' => $fields,
            'values' => $fields === [] ? [] : self::values($theme, $language, $areaSchema),
            'fingerprint' => $fields === [] ? '' : self::fingerprint($theme, $schema),
            'language' => $language,
        ];
    }

    public static function fingerprint(string $theme, array $fields): string
    {
        return hash('sha256', serialize([$theme, $fields, settingModel()->get('theme_content_' . $theme, '')]));
    }

    /**
     * $partial=false（主题内容页）：整张表单为准，未提交的字段写空。
     * $partial=true（Blox 编辑器里只改页头/页尾几项）：只改提交的键，其余保持原值。
     */
    public static function save(string $theme, string $language, array $input, string $expected, bool $partial = false): void
    {
        if (!LanguageRegistry::has($language)) throw new RuntimeException('tc_value');
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
            $all[$language] = self::merge($fields, is_array($all[$language] ?? null) ? $all[$language] : [], $input, $partial);
            settingModel()->set($key, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'theme');
            db()->commit();
        } catch (Throwable $error) {
            if (db()->getPdo()->inTransaction()) db()->rollback();
            settingModel()->clearCache();
            throw $error;
        }
    }

    /**
     * 一种语言保存后的值。整表保存：未提交的写空。部分保存：未提交的沿用原值，原值已不合规
     * （如旧版允许、现在拒绝的地址）就丢掉回到默认，不因别的字段拖垮这次保存。
     * 提交值不合规照常抛出，由调用方提示。
     *
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public static function merge(array $fields, array $previous, array $input, bool $partial): array
    {
        $values = [];
        foreach ($fields as $fieldKey => $field) {
            if (!$partial || array_key_exists($fieldKey, $input)) {
                $values[$fieldKey] = self::normalize($field, $input[$fieldKey] ?? '');
                continue;
            }
            if (!array_key_exists($fieldKey, $previous)) continue;
            try { $values[$fieldKey] = self::normalize($field, $previous[$fieldKey]); }
            catch (RuntimeException $error) { /* 丢弃不合规的旧值 */ }
        }
        return $values;
    }
}
