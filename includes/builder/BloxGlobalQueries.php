<?php
/**
 * Blox 全局查询（v1.25 Phase 2）：可复用的循环查询定义。
 *
 * 循环容器的 `_query` 可存 `{ref: gq_xxx}` 引用本表条目——一处修改、全站生效；
 * 查询体形状与内联 `_query` 完全一致（BloxLoopQuery::normalizeQuery 同一份规则）。
 * 悬挂引用（查询被删）由渲染端按空结果提示处理，绝不退化为全量或静态渲染。
 *
 * 授权：query_* 变更动作属 query_loop 作者端能力；已发布内容渲染永远免费。
 * 用量走 blox_query_refs 反向索引（文档保存时维护），删除被引用的查询会被拒绝。
 */

declare(strict_types=1);

final class BloxGlobalQueries
{
    public const ID_PATTERN = '/^gq_[a-f0-9]{12}$/';
    public const MAX_QUERIES = 100;
    private const NAME_MAX = 64;

    /** @var array<string,array<string,mixed>>|null 请求内目录缓存：query_id => {query_id,name,query,modified} */
    private static ?array $catalog = null;

    /** @psalm-suppress PossiblyUnusedMethod 测试专用（单测进程共享请求级缓存时复位） */
    public static function resetForTests(): void
    {
        self::$catalog = null;
    }

    public static function available(): bool
    {
        return db()->tableExists('blox_global_queries');
    }

    /** @return array<string,array<string,mixed>> */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        if (!self::available()) {
            return self::$catalog = [];
        }
        $catalog = [];
        foreach (bloxGlobalQueryModel()->allActive() as $row) {
            $queryId = (string) ($row['query_id'] ?? '');
            if (!preg_match(self::ID_PATTERN, $queryId)) {
                continue;
            }
            $body = json_decode((string) ($row['query'] ?? ''), true);
            $body = is_array($body) ? BloxLoopQuery::normalizeQuery($body) : null;
            if ($body === null) {
                continue;
            }
            $catalog[$queryId] = [
                'query_id' => $queryId,
                'name' => (string) ($row['name'] ?? ''),
                'query' => $body,
                'modified' => (int) ($row['modified'] ?? 0),
            ];
        }
        return self::$catalog = $catalog;
    }

    /** 引用解析：返回归一化查询体；不存在返回 null（悬挂引用）。 */
    public static function queryBody(string $queryId): ?array
    {
        return self::catalog()[$queryId]['query'] ?? null;
    }

    // ── 变更（query_* 动作，作者端授权） ──────────────────────────────

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @psalm-suppress PossiblyUnusedMethod 消费方为 blox_query_api（编辑器 UI 批次落地）与测试
     */
    public static function mutate(string $action, array $input, bool $advanced): array
    {
        if (!self::available()) {
            throw new RuntimeException(__('blox_feature_disabled'));
        }
        if (!$advanced) {
            throw new RuntimeException(__('blox_query_loop_license_required'));
        }
        $result = match ($action) {
            'query_add' => self::create($input),
            'query_update' => self::updateBody($input),
            'query_rename' => self::rename($input),
            'query_delete' => self::delete($input),
            default => throw new RuntimeException(__('blox_design_invalid')),
        };
        self::$catalog = null;
        return $result;
    }

    /** @param array<string,mixed> $input */
    private static function create(array $input): array
    {
        $name = self::assertName((string) ($input['name'] ?? ''));
        if (bloxGlobalQueryModel()->findByName($name) !== null) {
            throw new RuntimeException(__('blox_gquery_duplicate_name'));
        }
        $body = self::assertBody($input);
        $total = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_global_queries');
        if ($total >= self::MAX_QUERIES) {
            throw new RuntimeException(__('blox_gquery_limit', ['max' => self::MAX_QUERIES]));
        }
        $now = time();
        $row = [
            'query_id' => 'gq_' . bin2hex(random_bytes(6)),
            'name' => $name,
            'query' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'modified' => $now,
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        db()->insert('blox_global_queries', $row);
        return $row;
    }

    /** @param array<string,mixed> $input */
    private static function updateBody(array $input): array
    {
        $row = self::assertRow($input);
        $body = self::assertBody($input);
        $now = time();
        db()->update('blox_global_queries', [
            'query' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'modified' => $now,
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'updated_at' => $now,
        ], 'query_id = ?', [$row['query_id']]);
        return bloxGlobalQueryModel()->findByQueryId((string) $row['query_id']) ?? $row;
    }

    /** @param array<string,mixed> $input */
    private static function rename(array $input): array
    {
        $row = self::assertRow($input);
        $name = self::assertName((string) ($input['name'] ?? ''));
        $existing = bloxGlobalQueryModel()->findByName($name);
        if ($existing !== null && (string) $existing['query_id'] !== (string) $row['query_id']) {
            throw new RuntimeException(__('blox_gquery_duplicate_name'));
        }
        $now = time();
        db()->update('blox_global_queries', [
            'name' => $name,
            'modified' => $now,
            'updated_at' => $now,
        ], 'query_id = ?', [$row['query_id']]);
        return bloxGlobalQueryModel()->findByQueryId((string) $row['query_id']) ?? $row;
    }

    /** 删除：仍被文档引用时拒绝（先解除引用——反向索引给出用量）。 */
    private static function delete(array $input): array
    {
        $row = self::assertRow($input);
        $docs = (int) db()->fetchColumn(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_query_refs WHERE query_id = ?',
            [$row['query_id']]
        );
        if ($docs > 0) {
            throw new RuntimeException(__('blox_gquery_in_use', ['docs' => $docs]));
        }
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_global_queries WHERE query_id = ?', [$row['query_id']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_query_refs WHERE query_id = ?', [$row['query_id']]);
        return $row;
    }

    /** @param array<string,mixed> $input */
    private static function assertRow(array $input): array
    {
        $queryId = (string) ($input['id'] ?? '');
        if (!preg_match(self::ID_PATTERN, $queryId)) {
            throw new RuntimeException(__('blox_gquery_not_found'));
        }
        $row = bloxGlobalQueryModel()->findByQueryId($queryId);
        if ($row === null) {
            throw new RuntimeException(__('blox_gquery_not_found'));
        }
        // 乐观并发：调用方带上读取时的 modified，不一致即冲突
        if (array_key_exists('modified', $input) && (int) $input['modified'] !== (int) ($row['modified'] ?? 0)) {
            throw new RuntimeException(__('blox_design_conflict'));
        }
        return $row;
    }

    private static function assertName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException(__('blox_gquery_bad_name'));
        }
        return $name;
    }

    /** @param array<string,mixed> $input */
    private static function assertBody(array $input): array
    {
        $raw = $input['query'] ?? null;
        $body = is_array($raw) ? BloxLoopQuery::normalizeQuery($raw) : null;
        if ($body === null) {
            throw new RuntimeException(__('blox_design_invalid'));
        }
        return $body;
    }

    // ── 用量反向索引 ─────────────────────────────────────────────────

    /** 文档遍历：sections 树中引用到的 query_id => 引用次数。 */
    public static function collectReferences(array $sections): array
    {
        $counts = [];
        $walkElement = static function (array $element) use (&$walkElement, &$counts): void {
            $data = is_array($element['data'] ?? null) ? $element['data'] : [];
            $ref = is_array($data['_query'] ?? null) ? ($data['_query']['ref'] ?? null) : null;
            if (is_string($ref) && preg_match(self::ID_PATTERN, $ref)) {
                $counts[$ref] = ($counts[$ref] ?? 0) + 1;
            }
            foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
                if (is_array($child)) {
                    $walkElement($child);
                }
            }
        };
        foreach ($sections as $section) {
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        $walkElement($element);
                    }
                }
            }
        }
        return $counts;
    }

    /** 文档保存时调用：整体替换该文档的查询引用行。 */
    public static function replaceDocumentRefs(string $docKey, array $counts): void
    {
        if (!self::available() || $docKey === '' || strlen($docKey) > 64) {
            return;
        }
        $now = time();
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_query_refs WHERE doc_key = ?', [$docKey]);
        foreach ($counts as $queryId => $count) {
            if (!is_string($queryId) || !preg_match(self::ID_PATTERN, $queryId) || (int) $count < 1) {
                continue;
            }
            db()->insert('blox_query_refs', [
                'query_id' => $queryId,
                'doc_key' => $docKey,
                'ref_count' => (int) $count,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string,array{docs:int,refs:int}>
     * @psalm-suppress PossiblyUnusedMethod 消费方为 blox_query_api（编辑器 UI 批次落地）与测试
     */
    public static function usage(): array
    {
        if (!self::available()) {
            return [];
        }
        $usage = [];
        foreach (db()->fetchAll(
            'SELECT query_id, COUNT(*) AS docs, SUM(ref_count) AS refs FROM ' . DB_PREFIX . 'blox_query_refs GROUP BY query_id'
        ) as $row) {
            $usage[(string) $row['query_id']] = [
                'docs' => (int) ($row['docs'] ?? 0),
                'refs' => (int) ($row['refs'] ?? 0),
            ];
        }
        return $usage;
    }
}
