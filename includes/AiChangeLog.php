<?php
/**
 * Yikai CMS - AI 变更日志（撤销 + 审计）
 *
 * 每次 AI 写操作「应用」时记录一条：操作前快照(before) + 入参(input)。
 * 「撤销」时调对应 ability 的 revert(before, input) 回退。表按需自动创建。
 * 写入与撤销在各自的事务里完成：助手走 AiAssistantChanges，外部提案走 AiProposalService；本类只建表和读取。
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

    public static function get(int $id): ?array
    {
        self::ensureTable();
        return db()->fetchOne("SELECT * FROM `" . DB_PREFIX . "ai_change_log` WHERE id = ?", [$id]);
    }

    /** 某管理员最近的变更（含已撤销），用于 UI 展示。外部提案应用的记录不在这里列出，它们在「AI 草稿提案」页撤销。 */
    public static function recent(int $adminId, int $limit = 10): array
    {
        self::ensureTable();
        $limit = max(1, min(50, $limit));
        $rows = db()->fetchAll(
            "SELECT * FROM `" . DB_PREFIX . "ai_change_log` WHERE admin_id = ? ORDER BY id DESC LIMIT " . ($limit * 3),
            [$adminId]
        );
        return array_slice(array_values(array_filter($rows, static fn(array $row): bool => !self::isProposalChange($row))), 0, $limit);
    }

    /** 该管理员最近一条可由助手撤销的记录（跳过外部提案应用的记录） */
    public static function lastUndoable(int $adminId): ?array
    {
        self::ensureTable();
        $rows = db()->fetchAll(
            "SELECT * FROM `" . DB_PREFIX . "ai_change_log` WHERE admin_id = ? AND undone = 0 ORDER BY id DESC LIMIT 50",
            [$adminId]
        );
        foreach ($rows as $row) {
            if (!self::isProposalChange($row)) return $row;
        }
        return null;
    }

    /**
     * 由 AiProposalService 应用的提案记录：撤销必须走它的事务（核对应用后版本、同时改提案状态），
     * 助手的撤销端点不能按旧方式回退。迁移前的旧表没有该列，视为助手记录。
     */
    public static function isProposalChange(array $row): bool
    {
        return (int) ($row['proposal_item_id'] ?? 0) > 0;
    }
}
