<?php
declare(strict_types=1);
require_once __DIR__ . '/i18n/LanguageRegistry.php';

require_once __DIR__ . '/SensitiveSettings.php';

/** Content-only snapshots. Fixed table/setting allowlists, never package-provided SQL. */
final class SiteTemplateData
{
    public const TABLES = [
        'channels', 'contents', 'product_categories', 'brands', 'products', 'product_tags', 'product_tag_map',
        'product_routes', 'albums', 'album_photos', 'download_categories', 'downloads', 'jobs', 'timelines',
        'links', 'banner_groups', 'banners', 'nav_menus', 'form_templates', 'content_models', 'extfields',
        'metas', 'media', 'blocks_library', 'blox_templates', 'blox_global_classes', 'blox_global_queries',
        'blox_class_refs', 'blox_query_refs',
    ];

    /**
     * DB-independent schema contract shared by runtime import and release-side package validation.
     * A fresh-install regression test keeps this generated contract aligned with the actual schema.
     *
     * @return array<string,list<string>>
     */
    public static function contractSchema(): array
    {
        static $schema = null;
        if ($schema === null) {
            $loaded = require dirname(__DIR__) . '/config/site-template-schema.php';
            if (!is_array($loaded) || array_keys($loaded) !== self::TABLES) throw new RuntimeException('st_schema');
            $schema = $loaded;
        }
        return $schema;
    }

    public static function settingAllowed(string $key): bool
    {
        // 安全边界：白名单只能覆盖可移植的核心内容与主题展示配置，绝不能为插件前缀
        // （shop_*、seo_* 等）放宽。插件公开数据另走版本化适配器；密钥和交易数据永不入包。
        // 社媒入口（social-links 元素的唯一数据源）：精确键放行，值在导出/导入时归一（portableValue）
        // product_layout：商品目录元素未设布局时的兜底（元素设置依赖声明为 portable，2.0.3）
        if (in_array($key, ['site_lang', 'enabled_languages', 'social_links', 'product_layout'], true)) return true;
        if (!SensitiveSettings::isImportable($key)) return false;
        return (bool) preg_match('/^(?:site_(?:name|keywords|description|logo|favicon)|primary_color$|secondary_color$|current_theme$|theme_(?:style|color|content)|home_|header_|footer_|contact_|banner_|nav_|page_hero_|blox_(?:design_system$|design_theme(?:_draft)?$|widescreen_enabled$|custom_header_enabled$|custom_footer_enabled$))/', $key);
    }

    /** @return array<string,list<string>> */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::TABLES as $table) {
            if (!db()->tableExists($table)) throw new RuntimeException('st_schema');
            $rows = db()->isSqlite()
                ? db()->fetchAll('PRAGMA table_info(`' . DB_PREFIX . $table . '`)')
                : db()->fetchAll('SHOW COLUMNS FROM `' . DB_PREFIX . $table . '`');
            $columns = array_map(static fn(array $r): string => (string) ($r['name'] ?? $r['Field']), $rows);
            sort($columns);
            $schema[$table] = $columns;
        }
        return $schema;
    }

    public static function snapshot(bool $export = false): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' ORDER BY ' . ($table === 'product_tag_map' ? 'product_id, tag_id' : 'id'));
            if ($export) {
                $rows = array_values(array_filter($rows, static function (array $row) use ($table): bool {
                    if ($table === 'metas' && !in_array($row['owner_type'], ['channel', 'content', 'article', 'page', 'case', 'product', 'album', 'download', 'job'], true)) return false;
                    if (isset($row['deleted_at']) && $row['deleted_at'] !== '' && (int) $row['deleted_at'] > 0) return false;
                    if (in_array($table, ['channels', 'contents', 'products', 'jobs', 'blox_templates'], true) && (int) ($row['status'] ?? 1) !== 1) return false;
                    return true;
                }));
                foreach ($rows as &$row) {
                    foreach (['admin_id', 'user_id', 'views', 'likes', 'download_count'] as $field) {
                        if (array_key_exists($field, $row)) $row[$field] = 0;
                    }
                    if ($table === 'blox_templates') {
                        $row['draft_data'] = (string) ($row['published_data'] ?? '');
                        $row['source'] = 'user';
                        $row['source_ref'] = '';
                    }
                }
                unset($row);
            }
            $tables[$table] = $rows;
        }
        $settings = [];
        foreach (settingModel()->getAll() as $key => $value) {
            if ($export && preg_match('/(?:_history|_draft)(?:_|$)|^home_(?:blox|layout)_data(?:_|$)/', $key)) continue;
            if ($export && str_starts_with($key, 'theme_content_') && $key !== 'theme_content_' . (string) config('current_theme', 'default')) continue;
            if (self::settingAllowed($key)) $settings[$key] = $export ? self::portableValue($key, SensitiveSettings::sanitizeValue((string) $value)) : (string) $value;
        }
        if ($export) foreach ($settings as $key => $value) {
            if (preg_match('/^home_(blox|layout)_published(.*)$/', $key, $match)) $settings['home_' . $match[1] . '_data' . $match[2]] = $value;
            if ($key === 'blox_design_theme') $settings['blox_design_theme_draft'] = $value;
        }
        if ($export && isset($settings['theme_style_settings'])) {
            $styles = json_decode($settings['theme_style_settings'], true);
            if (is_array($styles) && is_array($styles['themes'] ?? null)) {
                $theme = (string) config('current_theme', 'default');
                $styles['themes'] = isset($styles['themes'][$theme]) ? [$theme => $styles['themes'][$theme]] : [];
                $settings['theme_style_settings'] = json_encode($styles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }
        ksort($settings);
        return ['tables' => $tables, 'settings' => $settings];
    }

    /** Core data guard. Plugin-owned state is tracked separately by the import journal. */
    public static function fingerprint(): string
    {
        settingModel()->clearCache();
        $state = self::snapshot();
        foreach (['forms', 'members', 'mail_log', 'content_revisions', 'blox_page_drafts'] as $table) {
            if (db()->tableExists($table)) {
                $state[$table] = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' ORDER BY id');
            }
        }
        return hash('sha256', serialize($state));
    }

    public static function validate(array $data, bool $checkReferences = true): void
    {
        if (!is_array($data['tables'] ?? null) || !is_array($data['settings'] ?? null)) throw new RuntimeException('st_invalid');
        if (array_keys($data['tables']) !== self::TABLES) throw new RuntimeException('st_invalid');
        $schema = self::schema();
        $ids = [];
        foreach ($data['tables'] as $table => $rows) {
            if (!is_array($rows) || count($rows) > 10000) throw new RuntimeException('st_limit');
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff(array_keys($row), $schema[$table]) !== []) throw new RuntimeException('st_schema');
                $id = $table === 'product_tag_map' ? (string) ($row['product_id'] ?? 0) . ':' . (string) ($row['tag_id'] ?? 0) : (int) ($row['id'] ?? 0);
                if ($table === 'product_tag_map' ? ((int) ($row['product_id'] ?? 0) <= 0 || (int) ($row['tag_id'] ?? 0) <= 0) : $id <= 0) throw new RuntimeException('st_schema');
                if (isset($ids[$table][$id])) throw new RuntimeException('st_invalid');
                $ids[$table][$id] = true;
                foreach ($row as $value) if (!is_scalar($value) && $value !== null) throw new RuntimeException('st_invalid');
            }
        }
        // Known relational references must never become silently dangling.
        $relations = [
            'channels' => ['parent_id' => 'channels', 'album_id' => 'albums'],
            'contents' => ['channel_id' => 'channels'],
            'product_categories' => ['parent_id' => 'product_categories'],
            'products' => ['category_id' => 'product_categories', 'brand_id' => 'brands'],
            'product_tag_map' => ['product_id' => 'products', 'tag_id' => 'product_tags'],
            'album_photos' => ['album_id' => 'albums'],
            'downloads' => ['category_id' => 'download_categories'],
        ];
        if ($checkReferences) foreach ($relations as $table => $fields) foreach ($data['tables'][$table] as $row) foreach ($fields as $field => $target) {
            $value = (int) ($row[$field] ?? 0);
            if ($value !== 0 && !isset($ids[$target][$value])) throw new RuntimeException('st_references');
        }
        foreach ($data['settings'] as $key => $value) {
            if (!is_string($key) || !is_string($value) || !self::settingAllowed($key)) throw new RuntimeException('st_invalid');
        }
        if (!LanguageRegistry::has((string) ($data['settings']['site_lang'] ?? 'zh-CN'))) throw new RuntimeException('st_invalid');
        $languages = json_decode($data['settings']['enabled_languages'] ?? '["zh-CN"]', true);
        if (!is_array($languages) || $languages === [] || array_diff($languages, LanguageRegistry::codes())) throw new RuntimeException('st_invalid');
    }

    /** Caller owns the transaction and checks the untouched-install guard. */
    public static function replace(array $snapshot, bool $checkReferences = true): void
    {
        self::validate($snapshot, $checkReferences);
        foreach (array_reverse(self::TABLES) as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
        foreach (self::TABLES as $table) foreach ($snapshot['tables'][$table] as $row) db()->insert($table, $row);
        foreach (array_keys(settingModel()->getAll()) as $key) {
            if (self::settingAllowed($key) && !array_key_exists($key, $snapshot['settings'])) db()->delete('settings', '`key` = ?', [$key]);
        }
        settingModel()->saveBatch($snapshot['settings']);
        settingModel()->clearCache();
    }

    /**
     * 需要结构化清洗的可移植设置。social_links 是 JSON 列表：只保留平台名合法、
     * 地址为站内相对路径或 http(s)/mailto/tel 的条目（渲染端 SocialLinksElement 仍会再过滤一次），
     * 导出与导入两侧同一规则——包里不会带出、站点也不会写入不安全的链接。导入侧只作用于
     * 外来包（SiteTemplateService::apply），本机备份的还原必须逐字节复原，不经这里。
     */
    public static function portableValue(string $key, string $value): string
    {
        if ($key !== 'social_links') {
            return $value;
        }
        $decoded = json_decode($value, true);
        $links = [];
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (!is_array($item) || count($links) >= 30) {
                continue;
            }
            $platform = strtolower(trim((string) ($item['platform'] ?? '')));
            $url = trim((string) ($item['url'] ?? ''));
            $safe = ($url !== '' && $url[0] === '/' && !str_starts_with($url, '//'))
                || preg_match('#^(?:https?://[^\s<>"\']+|mailto:[^\s<>"\']+|tel:[0-9+\-() ]+)$#i', $url) === 1;
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $platform) === 1 && $safe && strlen($url) <= 500) {
                $links[] = ['platform' => $platform, 'url' => $url];
            }
        }
        return json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
