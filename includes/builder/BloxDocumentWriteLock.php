<?php
/** Serialised compare-and-swap for Blox document writes. */

declare(strict_types=1);

final class BloxDocumentWriteLock
{
    /**
     * 写入前提：保护字段比较与 revision 校验必须基于同一份服务端文档。
     * 任一专业能力不可用时必须带 base_revision，缺失即拒绝，不依赖前端隐藏。
     */
    public static function assertRevision(string $currentJson, string $baseRevision): void
    {
        if ($baseRevision === '' && BloxFeaturePolicy::denied() !== []) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
        if ($baseRevision !== '' && !BloxDocumentPipeline::revisionMatches($currentJson, $baseRevision)) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
    }

    /**
     * 栏目行所属文档（单页、数据栏目落地页）：锁住栏目行，确认文档仍是校验时读到的那份，再写入。
     * 校验与渲染留在锁外，锁内只做重读与写库，避免两个保存都基于旧文档通过后互相覆盖。
     *
     * @template T
     * @param array{document_json:string,published_document_json:string} $expected
     * @param callable():array{document_json:string,published_document_json:string} $reload
     * @param callable():T $write
     * @return T
     */
    public static function channel(int $channelId, array $expected, callable $reload, callable $write): mixed
    {
        return self::transaction(
            static function () use ($channelId): void {
                // 锁总是存在的栏目行而不是草稿行：首次保存时草稿行还不存在，同样需要串行。
                self::lockRow('channels', 'id', $channelId);
            },
            static function () use ($expected, $reload): void {
                $current = $reload();
                foreach (['document_json', 'published_document_json'] as $key) {
                    if (!hash_equals(
                        BloxDocumentPipeline::fingerprint($expected[$key]),
                        BloxDocumentPipeline::fingerprint($current[$key])
                    )) {
                        throw new RuntimeException(__('blox_save_conflict'));
                    }
                }
            },
            $write
        );
    }

    /**
     * 设置表所属文档（首页）：以锚点设置行串行，比较的是库里原始值而不是 config() 的请求内缓存。
     * 调用方必须在读取可信文档之前取 $expectedRaw，这样两次读取之间的写入只会导致拒绝，不会漏检。
     *
     * @template T
     * @param array<string,?string> $expectedRaw
     * @param callable():T $write
     * @return T
     */
    public static function settings(string $anchorKey, array $expectedRaw, callable $write, string $anchorDefault = '0', string $anchorGroup = 'home'): mixed
    {
        self::ensureSettingRow($anchorKey, $anchorDefault, $anchorGroup);
        return self::transaction(
            static function () use ($anchorKey): void {
                self::lockRow('settings', '`key`', $anchorKey);
            },
            static function () use ($expectedRaw): void {
                if (self::rawSettings(array_keys($expectedRaw)) !== $expectedRaw) {
                    throw new RuntimeException(__('blox_save_conflict'));
                }
                if (function_exists('settingModel')) {
                    // 锁内的后续读取（历史快照等）必须看到刚提交的值。
                    settingModel()->clearCache();
                }
            },
            $write
        );
    }

    /**
     * @param list<string> $keys
     * @return array<string,?string>
     */
    public static function rawSettings(array $keys): array
    {
        $values = array_fill_keys($keys, null);
        foreach ($keys as $key) {
            $value = db()->fetchColumn('SELECT `value` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
            // 缺行与空值语义相同：锚点行被补建为空值时不能被误判为并发修改。
            $values[$key] = $value === false || $value === null || (string) $value === '' ? null : (string) $value;
        }
        return $values;
    }

    /**
     * @template T
     * @param callable():void $lock
     * @param callable():void $assert
     * @param callable():T $write
     * @return T
     */
    private static function transaction(callable $lock, callable $assert, callable $write): mixed
    {
        $database = db();
        $database->beginTransaction();
        try {
            $lock();
            $assert();
            $result = $write();
            $database->commit();
            return $result;
        } catch (Throwable $e) {
            $database->rollback();
            throw $e;
        }
    }

    private static function lockRow(string $table, string $column, int|string $value): void
    {
        if (db()->isSqlite()) {
            // SQLite 延迟事务的读取不加锁；一次无害写入拿到保留锁，其它写事务在此等待。
            db()->execute('UPDATE ' . DB_PREFIX . $table . ' SET id = id WHERE ' . $column . ' = ?', [$value]);
            return;
        }
        // InnoDB：锁读不建立一致性快照，锁到手后的重读能看到先提交的保存。
        db()->fetchAll('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE ' . $column . ' = ? FOR UPDATE', [$value]);
    }

    /** MySQL 对不存在的行加锁会退化成间隙锁并可能死锁；锚点行缺失时先补上出厂默认值。 */
    private static function ensureSettingRow(string $key, string $default, string $group): void
    {
        if (db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]) !== false) {
            return;
        }
        try {
            db()->execute(
                'INSERT INTO ' . DB_PREFIX . 'settings (`key`, `value`, `group`, `name`, `tip`) VALUES (?, ?, ?, ?, ?)',
                [$key, $default, $group, $key, '']
            );
        } catch (PDOException) {
            // 并发补行由唯一键兜底，另一方已插入即可。
        }
    }
}
