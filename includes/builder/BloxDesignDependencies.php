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

    /** 文档里引用设计刻度的数据键 → 刻度类别（值是 id；间距与容器宽度写作 token:<id>） */
    private const SCALE_KEYS = ['radius_token' => 'radius', 'shadow_token' => 'shadow', 'type_token' => 'type'];
    private const SCALE_ID = '/^[a-z0-9][a-z0-9_-]{0,47}$/';

    /**
     * 文档引用到的设计刻度（2.0.5）：圆角、阴影、排版用 *_token 键，间距用 style_margin… 与 style_padding… 键的 token:<id>（含四档），
     * 容器宽度用区块 max_width 的 token:<id>。
     * @param array<int,mixed> $sections @return array<string,list<string>> 类别 → id 列表
     */
    public static function scaleReferences(array $sections): array
    {
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) return;
            foreach ($node as $key => $value) {
                if (is_string($key) && isset(self::SCALE_KEYS[$key]) && is_string($value) && preg_match(self::SCALE_ID, $value) === 1) {
                    $found[self::SCALE_KEYS[$key]][$value] = true;
                } elseif (is_string($key) && (str_starts_with($key, 'style_margin') || str_starts_with($key, 'style_padding') || $key === 'max_width')) {
                    foreach (is_array($value) ? $value : [$value] as $tier) {
                        if (is_string($tier) && preg_match('/^token:([a-z0-9][a-z0-9_-]{0,47})$/', $tier, $m) === 1) {
                            $found[$key === 'max_width' ? 'container' : 'space'][$m[1]] = true;
                        }
                    }
                }
                if (is_array($value)) $walk($value);
            }
        };
        $walk($sections);
        $out = [];
        foreach ($found as $kind => $ids) {
            $list = array_keys($ids);
            sort($list);
            $out[$kind] = $list;
        }
        ksort($out);
        return $out;
    }

    /**
     * 模板包要带的刻度定义：文档引用到的、本站存在的刻度（出厂刻度各站都有，也一并带上，导入端按 id 判断要不要补）。
     * 别名刻度（值写 {id}）沿别名链把被引用的刻度一并带上（T07），否则对方站点只拿到一个落空的别名。
     * @param array<string,list<string>> $references @return array<string,list<array<string,mixed>>>
     */
    public static function exportScales(array $references): array
    {
        $snapshot = BloxDesignSystem::snapshot();
        $buckets = BloxDesignScale::KINDS + ['type' => 'typography'];
        $out = [];
        foreach ($references as $kind => $ids) {
            $bucket = $buckets[$kind] ?? null;
            if ($bucket === null) continue;
            if ($kind !== 'type') {
                $catalog = array_column($snapshot[$bucket] ?? [], null, 'id');
                for ($depth = 0, $pending = $ids; $pending !== [] && $depth < self::ALIAS_DEPTH; $depth++) {
                    $next = [];
                    foreach ($pending as $id) {
                        $ref = self::aliasTarget((string) ($catalog[$id]['value'] ?? ''));
                        if ($ref !== null && isset($catalog[$ref]) && !in_array($ref, $ids, true)) $next[] = $ids[] = $ref;
                    }
                    $pending = $next;
                }
            }
            $items = array_values(array_filter($snapshot[$bucket] ?? [], static fn (array $item): bool => in_array($item['id'], $ids, true)));
            if ($items !== []) $out[$kind] = array_map(static fn (array $item): array => array_diff_key($item, ['locked' => 1, 'version' => 1, 'status' => 1]), $items);
        }
        return $out;
    }

    /** 导入模式（T07）：site = 沿用本站设计（默认，本站已有同 id 的刻度不动）；template = 保留模板外观（值不同就改名新增并改写引用） */
    public const SCALE_MODES = ['site', 'template'];
    private const ALIAS_DEPTH = 4;
    private const ALIAS = '/^\{([a-z0-9][a-z0-9_-]{0,47})\}$/';

    /**
     * 刻度导入计划（T07）。默认模式只按原 id 补本站没有的刻度，不覆盖本站已有的同 id 刻度（那是本站的设计决定）。
     * 每个被文档引用的刻度给出一个结果（review），并给出要新增的项（plan）与要改写的引用（map）：
     *   add        本站没有，按原 id 新增（别名：site 模式留别名并补上被引用项，template 模式展平为具体值）
     *   same       本站有且有效值相同，沿用
     *   substitute 本站有、值不同，用本站值（site 模式）
     *   rename     本站有、值不同，改名新增并改写引用（template 模式）；改名 id 由来源值决定，重复导入得到同一个
     *   local      包里没定义、本站有，沿用
     *   missing    包里没定义、本站也没有（或别名解析不出值），走回退值
     *   limit      本站该类已满，不新增（也不改写引用）
     * review 里的 to 在 substitute 时也给出：检查页据此说明换成另一种模式会怎样。
     * @param array<string,list<string>> $references @param mixed $definitions 包里 design.scales
     * @return array{plan:array<string,list<array<string,mixed>>>,map:array<string,array<string,string>>,review:list<array{kind:string,id:string,action:string,to:string,local:string,source:string}>}
     */
    public static function planScales(array $references, mixed $definitions, string $mode = 'site', ?array $snapshot = null): array
    {
        if (!in_array($mode, self::SCALE_MODES, true)) throw new InvalidArgumentException('scale_mode');
        $snapshot ??= BloxDesignSystem::snapshot();
        $buckets = BloxDesignScale::KINDS + ['type' => 'typography'];
        $out = ['plan' => [], 'map' => [], 'review' => []];
        foreach ($references as $kind => $ids) {
            $bucket = $buckets[$kind] ?? null;
            if ($bucket === null) continue;
            $local = array_column($snapshot[$bucket] ?? [], null, 'id');
            $source = [];
            foreach (is_array($definitions) && is_array($definitions[$kind] ?? null) ? $definitions[$kind] : [] as $item) {
                $normalized = $kind === 'type' ? BloxDesignType::normalizeItem($item) : BloxDesignScale::normalizeItem($kind, $item);
                if ($normalized !== null) $source[$normalized['id']] = $normalized;
            }
            $room = ($kind === 'type' ? BloxDesignType::MAX_ITEMS : BloxDesignScale::MAX_ITEMS) - count($local);
            $adds = [];
            $add = static function (array $item) use (&$adds, &$room): bool {
                if (isset($adds[$item['id']])) return true;
                if ($room <= 0) return false;
                $adds[$item['id']] = $item;
                $room--;
                return true;
            };
            foreach ($ids as $id) {
                $row = ['kind' => $kind, 'id' => $id, 'action' => '', 'to' => '', 'local' => '', 'source' => ''];
                $item = $source[$id] ?? null;
                $mine = $local[$id] ?? null;
                if ($mine !== null) $row['local'] = self::scaleValue($kind, $mine, $local);
                if ($item === null) {
                    $out['review'][] = ['action' => $mine !== null ? 'local' : 'missing'] + $row;
                    continue;
                }
                $value = self::scaleValue($kind, $item, $source);
                $row['source'] = $value;
                if ($mine === null) {
                    $ref = $kind === 'type' ? null : self::aliasTarget((string) $item['value']);
                    if ($ref !== null && ($mode === 'template' || (!isset($local[$ref]) && (!isset($source[$ref]) || !$add(self::flatten($kind, $source[$ref], $source)))))) {
                        // 保留外观、或被引用项本站没有也补不上：展平为具体值；解析不出值就只能回退
                        if ($value === '') {
                            $out['review'][] = ['action' => 'missing'] + $row;
                            continue;
                        }
                        $item['value'] = $value;
                    }
                    $out['review'][] = ['action' => $add($item) ? 'add' : 'limit'] + $row;
                    continue;
                }
                if ($row['local'] === $value) {
                    $out['review'][] = ['action' => 'same'] + $row;
                    continue;
                }
                $row['to'] = self::renamedId($kind, $id, $value, $local, $adds);
                if ($mode === 'site') {
                    $out['review'][] = ['action' => 'substitute'] + $row;
                    continue;
                }
                $copy = ['id' => $row['to']] + self::flatten($kind, $item, $source);
                $reuse = isset($local[$row['to']]);
                if ($reuse || $add($copy)) {
                    $out['map'][$kind][$id] = $row['to'];
                    $out['review'][] = ['action' => 'rename'] + $row;
                } else {
                    $out['review'][] = ['action' => 'limit'] + $row;
                }
            }
            if ($adds !== []) $out['plan'][$kind] = array_values($adds);
        }
        return $out;
    }

    /**
     * 把文档里的刻度引用按改名表改写（template 模式）。键与 scaleReferences() 认的一致。
     * @param array<int,mixed> $sections @param array<string,array<string,string>> $map 类别 → 原 id → 新 id
     * @return array<int,mixed>
     */
    public static function remapScaleReferences(array $sections, array $map): array
    {
        if ($map === []) return $sections;
        $walk = static function (mixed $node) use (&$walk, $map): mixed {
            if (!is_array($node)) return $node;
            foreach ($node as $key => $value) {
                if (is_string($key) && isset(self::SCALE_KEYS[$key]) && is_string($value) && isset($map[self::SCALE_KEYS[$key]][$value])) {
                    $node[$key] = $map[self::SCALE_KEYS[$key]][$value];
                    continue;
                }
                if (is_string($key) && (str_starts_with($key, 'style_margin') || str_starts_with($key, 'style_padding') || $key === 'max_width')) {
                    $kind = $key === 'max_width' ? 'container' : 'space';
                    $swap = static fn (mixed $tier): mixed => is_string($tier) && preg_match('/^token:([a-z0-9][a-z0-9_-]{0,47})$/', $tier, $m) === 1 && isset($map[$kind][$m[1]])
                        ? 'token:' . $map[$kind][$m[1]] : $tier;
                    if (!is_array($value)) {
                        $node[$key] = $swap($value);
                        continue;
                    }
                    $value = array_map($swap, $value);
                }
                $node[$key] = is_array($value) ? $walk($value) : $value;
            }
            return $node;
        };
        return $walk($sections);
    }

    private static function aliasTarget(string $value): ?string
    {
        return preg_match(self::ALIAS, $value, $m) === 1 ? $m[1] : null;
    }

    /**
     * 有效值：别名沿链解析到具体值（解析不出返回空串）；排版比字号、行高、字距、字重，不比名称。
     * @param array<string,mixed> $item @param array<string,array<string,mixed>> $catalog 同类 id → 项
     */
    private static function scaleValue(string $kind, array $item, array $catalog): string
    {
        if ($kind === 'type') {
            return (string) json_encode(array_intersect_key($item, array_flip(['size', 'line_height', 'letter_spacing', 'weight'])), JSON_UNESCAPED_SLASHES);
        }
        $value = (string) ($item['value'] ?? '');
        for ($depth = 0; ($ref = self::aliasTarget($value)) !== null; $depth++) {
            if ($depth >= self::ALIAS_DEPTH || !isset($catalog[$ref])) return '';
            $value = (string) ($catalog[$ref]['value'] ?? '');
        }
        return $value;
    }

    /** 别名展平为具体值的副本（排版项原样） @param array<string,mixed> $item @param array<string,array<string,mixed>> $catalog @return array<string,mixed> */
    private static function flatten(string $kind, array $item, array $catalog): array
    {
        if ($kind !== 'type' && self::aliasTarget((string) $item['value']) !== null) $item['value'] = self::scaleValue($kind, $item, $catalog);
        return $item;
    }

    /**
     * 改名用的 id：原 id 截短 + 来源有效值的哈希。同一份模板重复导入得到同一个 id；
     * 本站碰巧已有这个 id 但值不同（极少）时再加序号。
     * @param array<string,array<string,mixed>> $local @param array<string,array<string,mixed>> $adds
     */
    private static function renamedId(string $kind, string $id, string $value, array $local, array $adds): string
    {
        $base = substr($id, 0, 40) . '-' . substr(hash('crc32b', $kind . '|' . $value), 0, 6);
        for ($n = 1, $candidate = $base; $n < 10; $candidate = substr($base, 0, 45) . '-' . ++$n) {
            $taken = $local[$candidate] ?? $adds[$candidate] ?? null;
            if ($taken === null || self::scaleValue($kind, $taken, $local + $adds) === $value) return $candidate;
        }
        return $candidate;
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
