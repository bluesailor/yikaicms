<?php
/** Media-library reference audit for destructive operations. */

declare(strict_types=1);

final class MediaUsageAudit
{
    /**
     * @param list<array<string,mixed>> $mediaRows
     * @return array<int,array{count:int,items:list<array<string,mixed>>}>
     */
    public static function audit(array $mediaRows): array
    {
        $targets = [];
        $result = [];
        $needles = [];
        foreach ($mediaRows as $media) {
            $id = (int) ($media['id'] ?? 0);
            $url = trim((string) ($media['url'] ?? ''));
            $key = self::referenceKey($url);
            if ($id <= 0 || $key === '') {
                continue;
            }
            $targets[$key][] = $id;
            $result[$id] = ['count' => 0, 'items' => []];
            foreach (self::searchNeedles($url) as $needle) {
                $needles[$needle] = true;
            }
        }
        if ($targets === []) {
            return $result;
        }

        self::scanBanners($targets, $result);
        self::scanJsonStores($targets, array_keys($needles), $result);

        foreach ($result as &$summary) {
            $deduped = [];
            foreach ($summary['items'] as $item) {
                $key = implode('|', [
                    (string) ($item['kind'] ?? ''),
                    (string) ($item['source_type'] ?? ''),
                    (string) ($item['source_id'] ?? ''),
                    (string) ($item['state'] ?? ''),
                    (string) ($item['path'] ?? ''),
                ]);
                $deduped[$key] = $item;
            }
            $summary['items'] = array_values($deduped);
            $summary['count'] = count($summary['items']);
        }
        unset($summary);

        return $result;
    }

    /** @param array<int,array{count:int,items:list<array<string,mixed>>}> $audit */
    public static function blockedMessage(array $audit, bool $batch = false): string
    {
        $blocked = array_filter($audit, static fn(array $item): bool => $item['count'] > 0);
        $usageCount = array_sum(array_column($blocked, 'count'));
        $labels = [];
        foreach ($blocked as $summary) {
            foreach ($summary['items'] as $item) {
                $source = trim((string) ($item['label'] ?? ''));
                if ($source === '') {
                    $source = __('media_usage_unknown_source');
                }
                $kind = __('media_usage_kind_' . (string) ($item['kind'] ?? 'unknown'));
                $labels[$source . ' (' . $kind . ')'] = true;
            }
        }
        $shown = array_slice(array_keys($labels), 0, 3);
        if (count($labels) > count($shown)) {
            $shown[] = __('media_usage_more', ['count' => count($labels) - count($shown)]);
        }

        return __($batch ? 'media_usage_batch_blocked' : 'media_usage_delete_blocked', [
            'files' => count($blocked),
            'count' => $usageCount,
            'sources' => implode(', ', $shown),
        ]);
    }

    /** @param array<string,list<int>> $targets @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanBanners(array $targets, array &$result): void
    {
        if (!self::tableExists('banners')) {
            return;
        }
        $rows = db()->fetchAll(
            'SELECT id,title,image,image_mobile FROM ' . DB_PREFIX . 'banners ORDER BY id'
        );
        foreach ($rows as $row) {
            $source = self::source('banner', (int) ($row['id'] ?? 0), (string) ($row['title'] ?? ''), 'current');
            self::addReference($targets, $result, $row['image'] ?? '', 'banner_image', $source, 'image');
            self::addReference($targets, $result, $row['image_mobile'] ?? '', 'banner_mobile_image', $source, 'image_mobile');
        }
        if (self::columnExists('banners', 'video')) {
            $rows = db()->fetchAll('SELECT id,title,video FROM ' . DB_PREFIX . 'banners ORDER BY id');
            foreach ($rows as $row) {
                $source = self::source('banner', (int) ($row['id'] ?? 0), (string) ($row['title'] ?? ''), 'current');
                self::addReference($targets, $result, $row['video'] ?? '', 'banner_video', $source, 'video');
            }
        }
    }

    /** @param array<string,list<int>> $targets @param list<string> $needles @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanJsonStores(array $targets, array $needles, array &$result): void
    {
        self::scanHomeDocuments($targets, $needles, $result);
        self::scanPageDrafts($targets, $needles, $result);
        self::scanPublishedContent($targets, $needles, $result);
        self::scanTemplates($targets, $needles, $result);
        self::scanBlockLibrary($targets, $needles, $result);
    }

    /** @param array<string,list<int>> $targets @param list<string> $needles @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanHomeDocuments(array $targets, array $needles, array &$result): void
    {
        if (!self::tableExists('settings')) {
            return;
        }
        $candidate = self::candidateCondition(['value'], $needles);
        $rows = db()->fetchAll(
            'SELECT id,`key`,`value` FROM ' . DB_PREFIX . 'settings'
            . " WHERE (`key` = ? OR `key` = ? OR `key` LIKE ? OR `key` LIKE ?) AND ({$candidate['sql']})",
            array_merge([
                'home_blox_data', 'home_blox_published', 'home_blox_data_%', 'home_blox_published_%',
            ], $candidate['params'])
        );
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            $state = str_contains($key, 'published') ? 'published' : 'draft';
            self::indexJson(
                (string) ($row['value'] ?? ''),
                self::source('home', (int) ($row['id'] ?? 0), __('media_usage_home'), $state),
                $targets,
                $result
            );
        }
    }

    /** @param array<string,list<int>> $targets @param list<string> $needles @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanPageDrafts(array $targets, array $needles, array &$result): void
    {
        if (!self::tableExists('blox_page_drafts')) {
            return;
        }
        foreach (['draft_data' => 'draft', 'published_data' => 'published'] as $field => $state) {
            if (!self::columnExists('blox_page_drafts', $field)) {
                continue;
            }
            $candidate = self::candidateCondition(['d.' . $field], $needles);
            $rows = db()->fetchAll(
                'SELECT d.page_id,d.' . $field . ' AS document,c.name AS label FROM ' . DB_PREFIX . 'blox_page_drafts d'
                . ' LEFT JOIN ' . DB_PREFIX . 'channels c ON c.id = d.page_id'
                . " WHERE {$candidate['sql']}",
                $candidate['params']
            );
            foreach ($rows as $row) {
                $pageId = (int) ($row['page_id'] ?? 0);
                self::indexJson(
                    (string) ($row['document'] ?? ''),
                    self::source('page', $pageId, (string) ($row['label'] ?? ''), $state),
                    $targets,
                    $result
                );
            }
        }
    }

    /** @param array<string,list<int>> $targets @param list<string> $needles @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanPublishedContent(array $targets, array $needles, array &$result): void
    {
        if (!self::tableExists('contents') || !self::columnExists('contents', 'blocks_data')) {
            return;
        }
        $candidate = self::candidateCondition(['blocks_data'], $needles);
        $rows = db()->fetchAll(
            'SELECT id,channel_id,title,blocks_data FROM ' . DB_PREFIX . "contents WHERE {$candidate['sql']}",
            $candidate['params']
        );
        foreach ($rows as $row) {
            self::indexJson(
                (string) ($row['blocks_data'] ?? ''),
                self::source('page', (int) ($row['channel_id'] ?? 0), (string) ($row['title'] ?? ''), 'published'),
                $targets,
                $result
            );
        }
    }

    /** @param array<string,list<int>> $targets @param list<string> $needles @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanTemplates(array $targets, array $needles, array &$result): void
    {
        if (!self::tableExists('blox_templates')) {
            return;
        }
        foreach (['draft_data' => 'draft', 'published_data' => 'published'] as $field => $state) {
            if (!self::columnExists('blox_templates', $field)) {
                continue;
            }
            $candidate = self::candidateCondition([$field], $needles);
            $rows = db()->fetchAll(
                'SELECT id,name,' . $field . ' AS document FROM ' . DB_PREFIX . "blox_templates WHERE {$candidate['sql']}",
                $candidate['params']
            );
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                self::indexJson(
                    (string) ($row['document'] ?? ''),
                    self::source('template', $id, (string) ($row['name'] ?? ''), $state),
                    $targets,
                    $result
                );
            }
        }
    }

    /** @param array<string,list<int>> $targets @param list<string> $needles @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function scanBlockLibrary(array $targets, array $needles, array &$result): void
    {
        if (!self::tableExists('blocks_library')) {
            return;
        }
        $candidate = self::candidateCondition(['data'], $needles);
        $rows = db()->fetchAll(
            'SELECT id,name,data FROM ' . DB_PREFIX . "blocks_library WHERE {$candidate['sql']}",
            $candidate['params']
        );
        foreach ($rows as $row) {
            self::indexJson(
                (string) ($row['data'] ?? ''),
                self::source('library', (int) ($row['id'] ?? 0), (string) ($row['name'] ?? ''), 'current'),
                $targets,
                $result
            );
        }
    }

    /** @param array<string,mixed> $source @param array<string,list<int>> $targets @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function indexJson(string $json, array $source, array $targets, array &$result): void
    {
        if (trim($json) === '') {
            return;
        }
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }
        self::visit($document, '$', $source, $targets, $result);
    }

    /** @param array<string,mixed> $source @param array<string,list<int>> $targets @param array<int,array{count:int,items:list<array<string,mixed>>}> $result */
    private static function visit(mixed $value, string $path, array $source, array $targets, array &$result): void
    {
        if (!is_array($value)) {
            return;
        }

        if (array_key_exists('bg_video', $value)) {
            self::addReference($targets, $result, $value['bg_video'], 'background_video', $source, $path . '.bg_video');
        }
        $type = is_string($value['type'] ?? null) ? $value['type'] : '';
        $data = is_array($value['data'] ?? null) ? $value['data'] : [];
        if ($type === 'video') {
            self::addReference($targets, $result, $data['url'] ?? '', 'video_element', $source, $path . '.data.url');
        } elseif ($type === 'home-banner-item') {
            self::addReference($targets, $result, $data['image'] ?? '', 'banner_image', $source, $path . '.data.image');
            self::addReference($targets, $result, $data['image_mobile'] ?? '', 'banner_mobile_image', $source, $path . '.data.image_mobile');
            self::addReference($targets, $result, $data['video'] ?? '', 'banner_video', $source, $path . '.data.video');
        }

        foreach ($value as $key => $child) {
            self::visit($child, $path . '.' . (string) $key, $source, $targets, $result);
        }
    }

    /** @param array<string,list<int>> $targets @param array<int,array{count:int,items:list<array<string,mixed>>}> $result @param array<string,mixed> $source */
    private static function addReference(
        array $targets,
        array &$result,
        mixed $reference,
        string $kind,
        array $source,
        string $path
    ): void {
        if (!is_string($reference)) {
            return;
        }
        $key = self::referenceKey($reference);
        foreach ($targets[$key] ?? [] as $mediaId) {
            $result[$mediaId]['items'][] = array_merge($source, [
                'kind' => $kind,
                'path' => $path,
                'reference' => $reference,
            ]);
        }
    }

    /** @return array{source_type:string,source_id:int,label:string,state:string,edit_url:string} */
    private static function source(string $type, int $id, string $label, string $state): array
    {
        $label = trim($label);
        if ($label === '') {
            $label = match ($type) {
                'banner' => __('media_usage_banner') . ' #' . $id,
                'page' => __('media_usage_page') . ' #' . $id,
                'template' => __('media_usage_template') . ' #' . $id,
                'library' => __('media_usage_library') . ' #' . $id,
                default => __('media_usage_unknown_source'),
            };
        }
        $editUrl = match ($type) {
            'banner' => '/admin/banner.php',
            'home' => '/admin/blox_editor.php?home=1',
            'page' => $id > 0 ? '/admin/blox_editor.php?id=' . $id : '',
            'template' => $id > 0 ? '/admin/blox_editor.php?template=' . $id : '',
            default => '',
        };
        return [
            'source_type' => $type,
            'source_id' => $id,
            'label' => $label,
            'state' => $state,
            'edit_url' => $editUrl,
        ];
    }

    /** @param list<string> $fields @param list<string> $needles @return array{sql:string,params:list<string>} */
    private static function candidateCondition(array $fields, array $needles): array
    {
        $parts = [];
        $params = [];
        foreach ($fields as $field) {
            foreach ($needles as $needle) {
                $parts[] = "INSTR(COALESCE({$field}, ''), ?) > 0";
                $params[] = $needle;
            }
        }
        return ['sql' => $parts === [] ? '1 = 0' : implode(' OR ', $parts), 'params' => $params];
    }

    /** @return list<string> */
    private static function searchNeedles(string $url): array
    {
        $needles = [];
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url !== '') {
            $needles[$url] = true;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $needles[$path] = true;
        }
        return array_keys($needles);
    }

    private static function referenceKey(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#/{2,}#', '/', '/' . ltrim(str_replace('\\', '/', $path), '/')) ?? $path;
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return 'local:' . $path;
        }
        $siteHost = '';
        if (function_exists('config')) {
            $siteHost = strtolower((string) parse_url((string) config('site_url', ''), PHP_URL_HOST));
        }
        return $siteHost !== '' && hash_equals($siteHost, $host)
            ? 'local:' . $path
            : 'external:' . $host . $path;
    }

    private static function tableExists(string $table): bool
    {
        return function_exists('db') && db()->tableExists($table);
    }

    private static function columnExists(string $table, string $column): bool
    {
        if (db()->isSqlite()) {
            $tableName = DB_PREFIX . $table;
            foreach (db()->fetchAll("PRAGMA table_info('{$tableName}')") as $item) {
                if ((string) ($item['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        return db()->fetchOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB_PREFIX . $table, $column]
        ) !== null;
    }
}
