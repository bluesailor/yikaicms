<?php
/**
 * 首页 Blox 文档与历史快照需要超过 settings.value 的 TEXT 容量。
 *
 * MySQL TEXT 只有 64KB，而首页草稿、发布快照和 10 份历史会共享设置表的值列。
 * 升级老站时扩大为 LONGTEXT；SQLite 的 TEXT 没有这个 64KB 上限，不需要改表。
 */

declare(strict_types=1);

$__settingsValueType = static function (): string {
    if (!db()->tableExists('settings') || db()->isSqlite()) {
        return '';
    }

    $row = db()->fetchOne(
        'SHOW COLUMNS FROM `' . DB_PREFIX . 'settings` LIKE \'value\''
    );

    return strtolower(trim((string) ($row['Type'] ?? $row['type'] ?? '')));
};

return [
    'id'    => '20260802_home_blox_storage',
    'title' => '首页 Blox 设置存储扩容',
    'desc'  => '将 MySQL settings.value 从 TEXT 扩大为 LONGTEXT，避免首页草稿、已发布快照和历史回退数据被 64KB 截断；SQLite 保持 TEXT。',
    'check' => static function () use ($__settingsValueType): bool {
        $type = $__settingsValueType();
        return $type === '' || $type === 'longtext';
    },
    'sqls' => [],
    'php' => static function () use ($__settingsValueType): string {
        $type = $__settingsValueType();
        if ($type === '') {
            return 'SQLite 或 settings 表不存在，无需扩容';
        }
        if ($type === 'longtext') {
            return 'settings.value 已是 LONGTEXT';
        }

        db()->execute(
            'ALTER TABLE `' . DB_PREFIX . 'settings` MODIFY COLUMN `value` LONGTEXT COMMENT \'值\''
        );

        return 'settings.value 已扩容为 LONGTEXT';
    },
];
