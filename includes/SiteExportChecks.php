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
        foreach ($data['settings'] ?? [] as $key => $value) {
            if (preg_match('/(?:_draft|_history|_data)(?:_|$)/', (string) $key)) continue;
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
