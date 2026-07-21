<?php
/**
 * Yikai CMS - AI 变更日志（撤销 + 审计）
 *
 * 每次 AI 助手写操作「应用」时记录一条：操作前快照(before) + 入参(input)。
 * 「撤销」时调对应 ability 的 revert(before, input) 回退。表按需自动创建。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class AiChangeLog
{
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (db()->tableExists('ai_change_log')) return;

        if (db()->isSqlite()) {
            db()->execute(
                "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_change_log` (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    admin_id INTEGER NOT NULL DEFAULT 0,
                    admin_name TEXT NOT NULL DEFAULT '',
                    ability TEXT NOT NULL,
                    target TEXT NOT NULL DEFAULT '',
                    summary TEXT NOT NULL DEFAULT '',
                    before_json TEXT,
                    input_json TEXT,
                    created_at INTEGER NOT NULL DEFAULT 0,
                    undone INTEGER NOT NULL DEFAULT 0,
                    undone_at INTEGER NOT NULL DEFAULT 0
                )"
            );
        } else {
            db()->execute(
                "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_change_log` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `admin_name` VARCHAR(100) NOT NULL DEFAULT '',
                    `ability` VARCHAR(100) NOT NULL,
                    `target` VARCHAR(200) NOT NULL DEFAULT '',
                    `summary` VARCHAR(500) NOT NULL DEFAULT '',
                    `before_json` MEDIUMTEXT NULL,
                    `input_json` MEDIUMTEXT NULL,
                    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
                    `undone` TINYINT NOT NULL DEFAULT 0,
                    `undone_at` INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `idx_admin` (`admin_id`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    /** 记录一次已应用的写操作，返回日志 id */
    public static function record(int $adminId, string $adminName, string $ability, string $target, string $summary, mixed $before, array $input): int
    {
        self::ensureTable();
        db()->execute(
            "INSERT INTO `" . DB_PREFIX . "ai_change_log`
             (admin_id, admin_name, ability, target, summary, before_json, input_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $adminId, $adminName, $ability, mb_substr($target, 0, 200), mb_substr($summary, 0, 500),
                json_encode($before, JSON_UNESCAPED_UNICODE),
                json_encode($input, JSON_UNESCAPED_UNICODE),
                time(),
            ]
        );
        return (int) db()->getPdo()->lastInsertId();
    }

    public static function get(int $id): ?array
    {
        self::ensureTable();
        return db()->fetchOne("SELECT * FROM `" . DB_PREFIX . "ai_change_log` WHERE id = ?", [$id]);
    }

    /** 某管理员最近的变更（含已撤销），用于 UI 展示 */
    public static function recent(int $adminId, int $limit = 10): array
    {
        self::ensureTable();
        $limit = max(1, min(50, $limit));
        return db()->fetchAll(
            "SELECT * FROM `" . DB_PREFIX . "ai_change_log` WHERE admin_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$adminId]
        );
    }

    /** 该管理员最近一条未撤销的记录 */
    public static function lastUndoable(int $adminId): ?array
    {
        self::ensureTable();
        return db()->fetchOne(
            "SELECT * FROM `" . DB_PREFIX . "ai_change_log` WHERE admin_id = ? AND undone = 0 ORDER BY id DESC LIMIT 1",
            [$adminId]
        );
    }

    public static function markUndone(int $id): void
    {
        db()->execute("UPDATE `" . DB_PREFIX . "ai_change_log` SET undone = 1, undone_at = ? WHERE id = ?", [time(), $id]);
    }
}
