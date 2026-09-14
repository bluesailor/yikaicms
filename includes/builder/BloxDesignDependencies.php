<?php
/** Blox design-token/style dependency extraction, diagnostics and usage index. */

declare(strict_types=1);

final class BloxDesignDependencies
{
    private const TOKEN_PATTERN = '/var\(--yk-color-([a-z][a-z0-9_-]{0,47})\)/';
    private const ID_PATTERN = '/^[a-z][a-z0-9_-]{0,47}$/';

    /** @param array<int,mixed> $sections @return array{design_tokens:list<string>,design_styles:list<string>} */
    public static function referencesFromSections(array $sections): array
    {
        $tokens = [];
        $styles = [];
        self::visit($sections, $tokens, $styles);
        $tokens = array_values(array_keys($tokens));
        $styles = array_values(array_keys($styles));
        sort($tokens);
        sort($styles);
        return ['design_tokens' => $tokens, 'design_styles' => $styles];
    }

    /** @return array{design_tokens:list<string>,design_styles:list<string>} */
    public static function referencesFromJson(string $json): array
    {
        if (trim($json) === '') {
            return ['design_tokens' => [], 'design_styles' => []];
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['design_tokens' => [], 'design_styles' => []];
        }
        return self::referencesFromSections(is_array($decoded) ? [$decoded] : []);
    }

    /**
     * @param array<string,mixed> $requirements
     * @param array{tokens?:list<array<string,mixed>>,styles?:list<array<string,mixed>>}|null $snapshot
     * @return array{missing_tokens:list<string>,missing_styles:list<string>,archived_tokens:list<string>,archived_styles:list<string>,complete:bool}
     */
    public static function diagnose(array $requirements, ?array $snapshot = null): array
    {
        $snapshot ??= BloxDesignSystem::snapshot();
        $tokens = self::catalogById($snapshot['tokens'] ?? []);
        $styles = self::catalogById($snapshot['styles'] ?? []);
        $requiredTokens = self::stringList($requirements['design_tokens'] ?? []);
        $requiredStyles = self::stringList($requirements['design_styles'] ?? []);
        $missingTokens = array_values(array_diff($requiredTokens, array_keys($tokens)));
        $missingStyles = array_values(array_diff($requiredStyles, array_keys($styles)));
        $archivedTokens = self::archived($requiredTokens, $tokens);
        $archivedStyles = self::archived($requiredStyles, $styles);
        return [
            'missing_tokens' => $missingTokens,
            'missing_styles' => $missingStyles,
            'archived_tokens' => $archivedTokens,
            'archived_styles' => $archivedStyles,
            'complete' => $missingTokens === [] && $missingStyles === [],
        ];
    }

    /** @param array<string,mixed> $requirements @return array{tokens:list<array<string,mixed>>,styles:list<array<string,mixed>>} */
    public static function exportDefinitions(array $requirements): array
    {
        $snapshot = BloxDesignSystem::snapshot();
        $styles = array_values(array_filter($snapshot['styles'], static fn(array $item): bool =>
            in_array($item['id'], $requirements['design_styles'] ?? [], true)));
        $styleRefs = self::referencesFromSections($styles);
        $tokenIds = array_merge($requirements['design_tokens'] ?? [], $styleRefs['design_tokens']);
        $tokens = array_values(array_filter($snapshot['tokens'], static fn(array $item): bool =>
            in_array($item['id'], $tokenIds, true)));
        return ['tokens' => $tokens, 'styles' => $styles];
    }

    /**
     * Definitions are diagnostic metadata, never instructions to overwrite local settings.
     * @param array<string,mixed> $requirements
     * @param array<string,mixed> $definitions
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>
     */
    public static function diagnoseImport(array $requirements, array $definitions, ?array $snapshot = null): array
    {
        $snapshot ??= BloxDesignSystem::snapshot();
        $result = self::diagnose($requirements, $snapshot);
        foreach (['tokens', 'styles'] as $kind) {
            $local = self::catalogById($snapshot[$kind] ?? []);
            $source = self::catalogById(is_array($definitions[$kind] ?? null) ? $definitions[$kind] : []);
            $result['conflicting_' . $kind] = [];
            $result['same_name_' . $kind] = [];
            $result['unverified_' . $kind] = [];
            foreach (self::stringList($requirements['design_' . $kind] ?? []) as $id) {
                if (!isset($source[$id])) {
                    if (isset($local[$id])) {
                        $result['unverified_' . $kind][] = $id;
                    }
                    continue;
                }
                if (isset($local[$id])) {
                    $sourceValue = $kind === 'tokens' ? ($source[$id]['value'] ?? null) : BloxDesignSystem::normalizeStyleSnapshot($source[$id]);
                    $localValue = $kind === 'tokens' ? ($local[$id]['value'] ?? null) : BloxDesignSystem::normalizeStyleSnapshot($local[$id]);
                    if ($sourceValue !== $localValue) {
                        $result['conflicting_' . $kind][] = $id;
                    }
                }
                $name = $source[$id]['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    foreach ($local as $localId => $item) {
                        if ($id !== $localId && ($item['name'] ?? '') === $name) {
                            $result['same_name_' . $kind][] = $id . ' -> ' . $localId;
                        }
                    }
                }
            }
        }
        return $result;
    }

    /** @return array{tokens:array<string,array{count:int,sources:list<array<string,mixed>>}>,styles:array<string,array{count:int,sources:list<array<string,mixed>>}>} */
    public static function usageSnapshot(): array
    {
        $usage = ['tokens' => [], 'styles' => []];
        if (!function_exists('db')) {
            return $usage;
        }
        try {
            if (db()->tableExists('blox_templates')) {
                $rows = db()->fetchAll(
                    'SELECT id,type,name,draft_data,published_data FROM ' . DB_PREFIX . 'blox_templates ORDER BY id'
                );
                foreach ($rows as $row) {
                    foreach (['draft_data' => 'draft', 'published_data' => 'published'] as $field => $state) {
                        self::indexJson($usage, (string) ($row[$field] ?? ''), [
                            'type' => 'template',
                            'id' => (int) ($row['id'] ?? 0),
                            'label' => (string) ($row['name'] ?? ''),
                            'template_type' => (string) ($row['type'] ?? ''),
                            'state' => $state,
                        ]);
                    }
                }
            }
        } catch (Throwable) {
            // Usage is advisory. A partial catalog is safer than blocking the editor.
        }
        try {
            if (db()->tableExists('contents')) {
                // 回收站中的内容不再算作引用，否则已删除页面会让样式永远显示“使用中”。
                $rows = db()->fetchAll(
                    'SELECT id,channel_id,title,blocks_data FROM ' . DB_PREFIX . 'contents'
                    . ' WHERE blocks_data IS NOT NULL AND blocks_data <> ? AND deleted_at IS NULL',
                    ['']
                );
                foreach ($rows as $row) {
                    self::indexJson($usage, (string) ($row['blocks_data'] ?? ''), [
                        'type' => 'page',
                        'id' => (int) ($row['channel_id'] ?? 0),
                        'content_id' => (int) ($row['id'] ?? 0),
                        'label' => (string) ($row['title'] ?? ''),
                        'state' => 'published',
                    ]);
                }
            }
        } catch (Throwable) {
        }
        try {
            if (db()->tableExists('blox_page_drafts')) {
                // 单页草稿与栏目落地页的已发布数据都存在这里（ChannelBloxDocument::saveAndPublish）。
                $hasPublished = function_exists('bloxPageDraftModel') && bloxPageDraftModel()->hasPublishedStorage();
                $rows = db()->fetchAll(
                    'SELECT page_id,draft_data' . ($hasPublished ? ',published_data' : '') . ' FROM ' . DB_PREFIX . 'blox_page_drafts'
                );
                foreach ($rows as $row) {
                    foreach (['draft_data' => 'draft', 'published_data' => 'published'] as $field => $state) {
                        self::indexJson($usage, (string) ($row[$field] ?? ''), [
                            'type' => 'page_draft',
                            'id' => (int) ($row['page_id'] ?? 0),
                            'label' => '#' . (int) ($row['page_id'] ?? 0),
                            'state' => $state,
                        ]);
                    }
                }
            }
        } catch (Throwable) {
        }
        try {
            if (db()->tableExists('settings')) {
                $rows = db()->fetchAll(
                    'SELECT `key`,`value` FROM ' . DB_PREFIX . 'settings'
                    . ' WHERE `value` LIKE ? OR `value` LIKE ?',
                    ['%--yk-color-%', '%_global_style%']
                );
                foreach ($rows as $row) {
                    self::indexJson($usage, (string) ($row['value'] ?? ''), [
                        'type' => 'setting',
                        'id' => 0,
                        'label' => (string) ($row['key'] ?? ''),
                        'state' => 'current',
                    ]);
                }
            }
        } catch (Throwable) {
        }
        return $usage;
    }

    /** @param array<string,array<string,array{count:int,sources:list<array<string,mixed>>}>> $usage @param array<string,mixed> $source */
    private static function indexJson(array &$usage, string $json, array $source): void
    {
        $refs = self::referencesFromJson($json);
        foreach (['design_tokens' => 'tokens', 'design_styles' => 'styles'] as $key => $bucket) {
            foreach ($refs[$key] as $id) {
                $usage[$bucket][$id] ??= ['count' => 0, 'sources' => []];
                $usage[$bucket][$id]['count']++;
                $usage[$bucket][$id]['sources'][] = $source;
            }
        }
    }

    /** @param array<string,true> $tokens @param array<string,true> $styles */
    private static function visit(mixed $value, array &$tokens, array &$styles): void
    {
        if (is_string($value)) {
            if (preg_match_all(self::TOKEN_PATTERN, $value, $matches)) {
                foreach ($matches[1] as $id) {
                    $tokens[(string) $id] = true;
                }
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if ($key === '_global_style' && is_string($child) && preg_match(self::ID_PATTERN, $child) === 1) {
                $styles[$child] = true;
            }
            self::visit($child, $tokens, $styles);
        }
    }

    /** @param array<int,mixed> $items @return array<string,array<string,mixed>> */
    private static function catalogById(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $out[$item['id']] = $item;
            }
        }
        return $out;
    }

    /** @param list<string> $ids @param array<string,array<string,mixed>> $catalog @return list<string> */
    private static function archived(array $ids, array $catalog): array
    {
        return array_values(array_filter(
            $ids,
            static fn(string $id): bool => isset($catalog[$id]) && ($catalog[$id]['status'] ?? '') === 'archived'
        ));
    }

    /** @return list<string> */
    private static function stringList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_string($item) && preg_match(self::ID_PATTERN, $item) === 1) {
                $out[] = $item;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}
