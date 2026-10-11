<?php

declare(strict_types=1);

/**
 * 旧「可复用块库」（blocks_library）下线（v2.1.0）：先报告、再预览、确认后迁移。
 *
 * 块库是组件之前的做法：库里存整段区块，页面里以 {library_id: N} 引用、渲染时展开。组件出来后它只剩
 * 旧版高级页面编辑器还在写。迁移做两件事：
 *   1. 每个库块另存成一个区块模板（source = import，source_ref = library:N），以后照样能插入；
 *   2. 所有引用就地换成库块当时的内容（与前台展开结果一致），页面不再依赖块库。
 * 库里的数据保留一个版本（本版渲染器仍会展开漏网的引用），下个版本连同表一起移除。
 */
final class BlocksLibraryMigration
{
    /** 可能出现引用的文档存放处：[表, 主键, 列] */
    private const STORES = [
        ['blox_page_drafts', 'id', ['draft_data', 'published_data']],
        ['contents', 'id', ['blocks_data']],
        ['blox_templates', 'id', ['draft_data', 'published_data']],
        ['settings', 'id', ['value']],
    ];

    public static function available(): bool
    {
        return db()->tableExists('blocks_library');
    }

    /**
     * 报告：每个库块、被多少处引用、在哪里；以及已经迁移过的（有对应区块模板的）。
     *
     * @return array{entries:list<array{id:int,name:string,refs:int,places:list<string>,migrated:bool}>,references:int}
     */
    public static function report(): array
    {
        if (!self::available()) {
            return ['entries' => [], 'references' => 0];
        }
        $places = [];
        $total = 0;
        foreach (self::documents() as [$table, $column, $id, $tree]) {
            foreach (self::referencedIds($tree) as $libId => $count) {
                $places[$libId][] = $table . '#' . $id . ($column === 'published_data' ? ' (published)' : '');
                $total += $count;
            }
        }
        $migrated = self::migratedIds();
        $entries = [];
        foreach (db()->fetchAll('SELECT id, name FROM ' . DB_PREFIX . 'blocks_library ORDER BY id') as $row) {
            $id = (int) $row['id'];
            $entries[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'refs' => count($places[$id] ?? []),
                'places' => array_values(array_unique($places[$id] ?? [])),
                'migrated' => isset($migrated[$id]),
            ];
        }
        return ['entries' => $entries, 'references' => $total];
    }

    /**
     * 迁移：库块另存为区块模板（已存过的跳过）、全部引用就地展开。整体一个事务。
     *
     * @return array{templates:int,documents:int,references:int}
     */
    public static function migrate(int $adminId = 0): array
    {
        if (!self::available()) {
            return ['templates' => 0, 'documents' => 0, 'references' => 0];
        }
        $library = [];
        foreach (db()->fetchAll('SELECT id, name, data FROM ' . DB_PREFIX . 'blocks_library ORDER BY id') as $row) {
            $section = json_decode((string) ($row['data'] ?? ''), true);
            if (is_array($section)) {
                unset($section['library_id'], $section['library_name']);
                $library[(int) $row['id']] = ['name' => (string) $row['name'], 'section' => $section];
            }
        }
        $templates = 0;
        $documents = 0;
        $references = 0;
        db()->beginTransaction();
        try {
            $migrated = self::migratedIds();
            foreach ($library as $id => $entry) {
                if (isset($migrated[$id])) {
                    continue;
                }
                $json = json_encode(['schema' => 1, 'settings' => [], 'sections' => [$entry['section']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $processed = BloxFeaturePolicy::asTrustedWrite(static fn (): array => BloxDocumentPipeline::process($json, 'lib' . $id));
                $templateId = bloxTemplateModel()->createDraft(
                    'section',
                    $entry['name'] !== '' ? $entry['name'] : '#' . $id,
                    $processed['json'],
                    'import',
                    BloxDocumentPipeline::SCHEMA_VERSION,
                    BloxTemplateImporter::deriveRequirements($processed['sections']),
                    '',
                    $adminId,
                    'library:' . $id
                );
                bloxTemplateModel()->publishDraft($templateId);
                $templates++;
            }
            foreach (self::documents() as [$table, $column, $pk, $tree]) {
                $count = 0;
                $rewritten = self::inline($tree, $library, $count);
                if ($count === 0) {
                    continue;
                }
                db()->update($table, [$column => json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)], 'id = ?', [$pk]);
                $documents++;
                $references += $count;
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        if (function_exists('do_action')) {
            do_action('data_changed', 'blox_templates', null);
        }
        if (class_exists('HtmlCache')) {
            HtmlCache::invalidate();
        }
        return ['templates' => $templates, 'documents' => $documents, 'references' => $references];
    }

    /**
     * 引用就地展开：区块列表里 {library_id: N} 换成库块内容（保留引用处的区块 id），库块已不存在的引用原样留下。
     *
     * @param array<int,array{name:string,section:array<string,mixed>}> $library
     */
    public static function inline(mixed $tree, array $library, int &$count): mixed
    {
        if (!is_array($tree)) {
            return $tree;
        }
        foreach ($tree as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $libId = (int) ($value['library_id'] ?? 0);
            if ($libId > 0 && isset($library[$libId])) {
                $section = $library[$libId]['section'];
                if (isset($value['id'])) {
                    $section['id'] = $value['id'];
                }
                $tree[$key] = $section;
                $count++;
                continue;
            }
            $tree[$key] = self::inline($value, $library, $count);
        }
        return $tree;
    }

    /** @return array<int,int> 文档树里引用到的库块 id => 次数 */
    private static function referencedIds(mixed $tree): array
    {
        $out = [];
        $walk = static function (mixed $node) use (&$walk, &$out): void {
            if (!is_array($node)) {
                return;
            }
            $libId = (int) ($node['library_id'] ?? 0);
            if ($libId > 0) {
                $out[$libId] = ($out[$libId] ?? 0) + 1;
                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($tree);
        return $out;
    }

    /** @return array<int,true> 已经另存成区块模板的库块 id */
    private static function migratedIds(): array
    {
        $out = [];
        foreach (db()->fetchAll('SELECT source_ref FROM ' . DB_PREFIX . "blox_templates WHERE source = 'import' AND source_ref LIKE 'library:%'") as $row) {
            if (preg_match('/^library:(\d+)$/', (string) $row['source_ref'], $m) === 1) {
                $out[(int) $m[1]] = true;
            }
        }
        return $out;
    }

    /**
     * 含引用的文档：[表, 列, 主键值, 解码后的树]。
     *
     * @return list<array{0:string,1:string,2:int,3:array<mixed>}>
     */
    private static function documents(): array
    {
        $out = [];
        foreach (self::STORES as [$table, $pk, $columns]) {
            if (!db()->tableExists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $rows = db()->fetchAll(
                    'SELECT ' . $pk . ' AS pk, ' . $column . ' AS doc FROM ' . DB_PREFIX . $table . ' WHERE ' . $column . ' LIKE ?',
                    ['%"library_id"%']
                );
                foreach ($rows as $row) {
                    $tree = json_decode((string) ($row['doc'] ?? ''), true);
                    if (is_array($tree)) {
                        $out[] = [$table, $column, (int) $row['pk'], $tree];
                    }
                }
            }
        }
        return $out;
    }
}
