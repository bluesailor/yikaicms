<?php
declare(strict_types=1);

/** 根据导出快照核对栏目链接；不请求外部 URL，不执行主题 PHP。 */
final class SiteExportChecks
{
    public static function inspect(array $data, array $channels, string $origin): array
    {
        $included = array_fill_keys(array_map(static fn(array $row): int => (int) $row['id'], $data['tables']['channels'] ?? []), true);
        $byId = [];
        foreach ($channels as $channel) $byId[(int) $channel['id']] = $channel;
        $routes = [];
        foreach ($channels as $channel) {
            $id = (int) $channel['id'];
            $slug = rawurlencode((string) ($channel['slug'] ?? ''));
            $paths = ['/page/' . $id . '.html', '/list/' . $id . '.html'];
            if ($slug !== '') {
                $paths[] = '/' . $slug . '.html';
                $parent = $byId[(int) ($channel['parent_id'] ?? 0)] ?? [];
                if (($channel['type'] ?? '') === 'page' && !empty($parent['slug'])) $paths[] = '/' . rawurlencode((string) $parent['slug']) . '/' . $slug . '.html';
            }
            $lang = (string) ($channel['lang'] ?? '');
            foreach ($paths as $path) {
                // 路径可能被多语言记录共享；任意一个目标被导出就不误报。
                $routes[$path][] = $id;
                if ($lang !== '') $routes['/' . rawurlencode($lang) . $path][] = $id;
            }
        }
        $sources = [];
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $enabledLanguages = self::enabledLanguageSet($settings);
        foreach ($settings as $key => $value) {
            if (preg_match('/(?:_draft|_history|_data)(?:_|$)/', (string) $key)) continue;
            if (self::isDisabledLanguageSetting((string) $key, $enabledLanguages)) continue;
            $edit = str_starts_with((string) $key, 'footer_') ? '/admin/setting.php?tab=footer' : '/admin/setting_home.php';
            $sources[] = ['value' => $value, 'label' => (string) $key, 'url' => $edit];
        }
        foreach (['nav_menus', 'channels', 'contents', 'products'] as $table) {
            foreach ($data['tables'][$table] ?? [] as $row) {
                $edit = match ($table) {
                    'channels' => '/admin/channel.php?edit=' . (int) $row['id'],
                    'contents' => '/admin/content_edit.php?id=' . (int) $row['id'],
                    'products' => '/admin/product_edit.php?id=' . (int) $row['id'],
                    default => '/admin/nav_menu.php',
                };
                $sources[] = ['value' => $row, 'label' => (string) ($row['title'] ?? $row['name'] ?? $row['id']), 'url' => $edit];
            }
        }
        $issues = [];
        $scanned = 0;
        foreach (array_slice($sources, 0, 3000) as $source) {
            $scanned++;
            foreach (self::links($source['value']) as $link) {
                $path = self::localPath($link, $origin);
                if ($path === null) continue;
                $ids = $routes[$path] ?? [];
                if ($ids === [] || array_filter($ids, static fn(int $id): bool => isset($included[$id])) !== []) continue;
                $key = $source['url'] . '|' . $path;
                $issues[$key] = ['code' => 'usability_export_omitted', 'label' => mb_substr($source['label'], 0, 120),
                    'detail' => mb_substr($link, 0, 300), 'url' => $source['url']];
                if (count($issues) >= 100) break 2;
            }
        }
        return ['issues' => array_values($issues), 'scanned' => $scanned, 'limited' => count($sources) > 3000 || count($issues) >= 100];
    }

    /**
     * 元素设置依赖（2.0.3）：包里用到的 Blox 元素依赖哪些站点设置。
     * - 可移植设置为空 → 提示（导入后该元素没有内容可显示，如页脚社媒入口为空）；
     * - 属于目标站点的设置（备案号等）→ 提示导入后需在目标站填写。
     * 只读快照、不执行元素渲染；元素类型从所有文档 JSON 里按 {type, data} 节点形状收集。
     *
     * @return list<array{code:string,label:string,detail:string,url:string}>
     */
    public static function settingDependencyIssues(array $data): array
    {
        if (!class_exists('BuilderRegistry') && is_file(ROOT_PATH . '/includes/builder/bootstrap.php')) {
            require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        }
        if (!class_exists('BuilderRegistry')) return [];
        $types = [];
        self::collectElementTypes($data['settings'] ?? [], $types);
        foreach ($data['tables'] ?? [] as $rows) self::collectElementTypes($rows, $types);
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $issues = [];
        foreach (array_keys($types) as $type) {
            $element = BuilderRegistry::get($type);
            if ($element === null) continue;
            foreach ($element->settingDependencies() as $key => $scope) {
                if (isset($issues[$key])) continue;
                if ($scope === 'site') {
                    $issues[$key] = ['code' => 'usability_export_setting_site', 'label' => $element->label(), 'detail' => $key, 'url' => self::settingUrl($key)];
                } elseif ($scope === 'portable' && !self::settingHasValue($settings, $key)) {
                    $issues[$key] = ['code' => 'usability_export_setting_empty', 'label' => $element->label(), 'detail' => $key, 'url' => self::settingUrl($key)];
                }
            }
        }
        return array_values($issues);
    }

    /**
     * 自动封面陷阱（2.0.3）：Blox 单页有正文封面且开着「正文顶部显示头图」，前台会在 Blox 内容之前
     * 输出整宽封面图，常把标题挤出首屏。英文模板一批 36 页中招，只有做首屏位置检查才发现。
     *
     * @return list<array{code:string,label:string,detail:string,url:string}>
     */
    public static function bloxCoverIssues(array $data): array
    {
        $covers = [];
        foreach ($data['tables']['contents'] ?? [] as $row) {
            if (($row['content_type'] ?? '') !== 'blocks' || trim((string) ($row['cover'] ?? '')) === '') continue;
            $covers[(int) ($row['channel_id'] ?? 0)] ??= (string) $row['cover'];
        }
        $issues = [];
        foreach ($data['tables']['channels'] ?? [] as $channel) {
            $id = (int) ($channel['id'] ?? 0);
            if (($channel['type'] ?? '') !== 'page' || (int) ($channel['show_cover'] ?? 1) !== 1 || !isset($covers[$id])) continue;
            $issues[] = ['code' => 'usability_export_blox_cover', 'label' => mb_substr((string) ($channel['name'] ?? '#' . $id), 0, 120),
                'detail' => mb_substr($covers[$id], 0, 300), 'url' => '/admin/blox_editor.php?id=' . $id];
            if (count($issues) >= 50) break;
        }
        return $issues;
    }

    /** @param array<string,true> $types */
    private static function collectElementTypes(mixed $value, array &$types, int $depth = 0): void
    {
        if ($depth > 40 || count($types) > 300) return;
        if (is_string($value)) {
            if ($value === '' || ($value[0] !== '{' && $value[0] !== '[')) return;
            $value = json_decode($value, true);
        }
        if (!is_array($value)) return;
        if (is_string($value['type'] ?? null) && is_array($value['data'] ?? null)
            && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $value['type']) === 1) {
            $types[$value['type']] = true;
        }
        foreach ($value as $child) {
            if (is_array($child) || is_string($child)) self::collectElementTypes($child, $types, $depth + 1);
        }
    }

    /** 基键或任一语言变体（site_name_en 等）有值即算有值；空 JSON 列表算空。 */
    private static function settingHasValue(array $settings, string $key): bool
    {
        foreach ($settings as $name => $value) {
            if ($name !== $key && !preg_match('/^' . preg_quote($key, '/') . '_[a-zA-Z-]{2,5}$/', (string) $name)) continue;
            $value = trim((string) $value);
            if ($value !== '' && $value !== '[]' && $value !== '{}') return true;
        }
        return false;
    }

    private static function settingUrl(string $key): string
    {
        return match (true) {
            $key === 'social_links' => '/admin/setting_social.php',
            $key === 'product_layout' => '/admin/product_setting.php',
            str_starts_with($key, 'nav_') => '/admin/nav_menu.php',
            str_starts_with($key, 'home_') => '/admin/setting_home.php',
            str_starts_with($key, 'footer_') => '/admin/setting.php?tab=footer',
            default => '/admin/setting.php',
        };
    }

    /** @return array<string,true>|null null 表示配置为空或无效，按前台兼容规则不过滤任何语言。 */
    private static function enabledLanguageSet(array $settings): ?array
    {
        $raw = $settings['enabled_languages'] ?? null;
        if (!is_string($raw) || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) return null;
        $enabled = [];
        foreach ($decoded as $language) {
            if (is_string($language) && trim($language) !== '') $enabled[trim($language)] = true;
        }
        return $enabled === [] ? null : $enabled;
    }

    /** 只识别实际存在的语言代码后缀；mobile、desktop 等普通后缀不能被误当成语言。 */
    private static function isDisabledLanguageSetting(string $key, ?array $enabledLanguages): bool
    {
        if ($enabledLanguages === null) return false;
        $languages = function_exists('availableLanguages')
            ? array_keys(availableLanguages())
            : ['zh-CN', 'en', 'ja'];
        foreach ($languages as $language) {
            if (str_ends_with($key, '_' . $language)) return !isset($enabledLanguages[$language]);
        }
        return false;
    }

    private static function links(mixed $value, string $key = '', int $depth = 0): array
    {
        if ($depth > 24) return [];
        if (is_array($value)) {
            $links = [];
            foreach ($value as $childKey => $child) $links = array_merge($links, self::links($child, (string) $childKey, $depth + 1));
            return $links;
        }
        if (!is_string($value)) return [];
        $json = json_decode($value, true);
        if (is_array($json)) return self::links($json, '', $depth + 1);
        $links = [];
        if (in_array($key, ['url', 'link', 'href', 'link_url', 'button_url', 'redirect_url'], true)) $links[] = $value;
        preg_match_all('~\bhref\s*=\s*["\']([^"\']+)["\']~i', $value, $matches);
        return array_merge($links, $matches[1]);
    }

    private static function localPath(string $link, string $origin): ?string
    {
        $link = html_entity_decode(trim($link), ENT_QUOTES, 'UTF-8');
        $parts = parse_url($link);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return null;
        if (isset($parts['host'])) {
            $own = parse_url($origin);
            if (!is_array($own) || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($own['host'] ?? ''))
                || ($parts['port'] ?? null) !== ($own['port'] ?? null)) return null;
        } elseif (isset($parts['scheme']) || !str_starts_with($link, '/')) return null;
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '/index.php') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (is_string($query['yk_route'] ?? null)) $path = '/' . ltrim($query['yk_route'], '/');
        }
        return $path;
    }
}
