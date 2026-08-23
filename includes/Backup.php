<?php
/**
 * Yikai CMS - 数据库备份服务
 *
 * 从 admin/database.php 抽出来的 generateBackupSql，方便 admin / CLI 共享。
 * 同时兼容 MySQL 和 SQLite。
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

class Backup
{
    /**
     * 生成完整备份 SQL 字符串。
     *
     * @param string[] $tables  要备份的表名列表（不含前缀也行，调用方保证）
     * @param bool $structure   包含 CREATE TABLE 语句
     * @param bool $data        包含 INSERT 数据
     */
    public static function generateSql(array $tables, bool $structure = true, bool $data = true, string $format = 'default'): string
    {
        // Defense-in-depth：表名拼到 SQL 里无法参数化，强制 allowlist 校验。
        // 调用方都该从 information_schema / sqlite_master 取，但万一直接传 POST 数据进来。
        foreach ($tables as $t) {
            if (!is_string($t) || !preg_match('/^[a-zA-Z0-9_]+$/', $t)) {
                throw new \InvalidArgumentException("非法表名（仅允许 [a-zA-Z0-9_]）：" . (is_string($t) ? $t : gettype($t)));
            }
        }

        $isSqlite = db()->isSqlite();
        $format = self::normalizeFormat($format);
        $sql = self::buildHeader($format, $isSqlite);
        if ($isSqlite) {
            $sql .= "-- 数据库: SQLite\n\n";
        } else {
            $charset = $format === 'mysql57_utf8' ? 'utf8' : 'utf8mb4';
            $sql .= "SET NAMES {$charset};\n";
            if ($format === 'default') {
                $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
            }
            $sql .= "\n";
        }

        foreach ($tables as $table) {
            if ($structure) {
                if ($isSqlite) {
                    $row = db()->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
                    $sql .= "DROP TABLE IF EXISTS {$table};\n" . ($row['sql'] ?? '') . ";\n\n";
                } else {
                    $create = db()->fetchOne("SHOW CREATE TABLE `{$table}`");
                    $createSql = (string) ($create['Create Table'] ?? '');
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . self::normalizeCreateSql($createSql, $format) . ";\n\n";
                }
            }
            if ($data) {
                $rows = db()->fetchAll("SELECT * FROM `{$table}`");
                if (!empty($rows)) {
                    $q = $isSqlite ? '"' : '`';
                    $cols = $q . implode("{$q}, {$q}", array_keys($rows[0])) . $q;
                    foreach (array_chunk($rows, 100) as $chunk) {
                        $sql .= "INSERT INTO {$q}{$table}{$q} ({$cols}) VALUES\n";
                        $vals = [];
                        foreach ($chunk as $r) {
                            $escaped = array_map(
                                fn($v) => $v === null ? 'NULL' : "'" . self::escapeValue((string) $v, $isSqlite) . "'",
                                array_values($r)
                            );
                            $vals[] = '(' . implode(', ', $escaped) . ')';
                        }
                        $sql .= implode(",\n", $vals) . ";\n\n";
                    }
                }
            }
        }

        if (!$isSqlite && $format === 'default') {
            $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        }
        return $sql;
    }

    private static function normalizeFormat(string $format): string
    {
        return in_array($format, ['default', 'mysql57_utf8'], true) ? $format : 'default';
    }

    private static function escapeValue(string $value, bool $isSqlite): string
    {
        return $isSqlite ? str_replace("'", "''", $value) : addslashes($value);
    }

    private static function buildHeader(string $format, bool $isSqlite): string
    {
        if ($isSqlite) {
            return "-- Yikai CMS 数据库备份\n-- 时间: " . date('Y-m-d H:i:s') . "\n";
        }

        if ($format === 'default') {
            return "-- Yikai CMS 数据库备份\n-- 时间: " . date('Y-m-d H:i:s') . "\n-- 数据库: " . DB_NAME . "\n\n";
        }

        return "-- MySQL dump 10.13  Distrib 5.7.26, for Linux (x86_64)\n"
            . "--\n"
            . "-- Host: localhost    Database: " . DB_NAME . "\n"
            . "-- ------------------------------------------------------\n"
            . "-- Server version\t5.7.26-log\n\n";
    }

    private static function normalizeCreateSql(string $sql, string $format): string
    {
        if ($format !== 'mysql57_utf8') {
            return $sql;
        }

        $sql = preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8_general_ci', $sql) ?? $sql;
        $sql = str_replace('utf8mb4_general_ci', 'utf8_general_ci', $sql);
        $sql = str_replace('utf8mb4_unicode_ci', 'utf8_general_ci', $sql);
        $sql = str_replace('utf8mb4', 'utf8', $sql);
        $sql = preg_replace('/\s+ROW_FORMAT=\w+/i', '', $sql) ?? $sql;

        return $sql;
    }

    /**
     * 列出所有 yikai_ 前缀表（默认备份范围）。
     * @return string[]
     */
    public static function listPrefixedTables(): array
    {
        if (db()->isSqlite()) {
            $rows = db()->fetchAll(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ? ORDER BY name",
                [DB_PREFIX . '%']
            );
        } else {
            $rows = db()->fetchAll(
                "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ? ORDER BY table_name",
                [DB_NAME, DB_PREFIX . '%']
            );
        }
        return array_column($rows, 'name');
    }

    /**
     * 把 SQL 写到 storage/backups/{filename}，返回完整路径。
     * 自动创建目录。
     */
    public static function writeToBackupsDir(string $sql, ?string $filename = null): string
    {
        $dir = ROOT_PATH . '/storage/backups';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $filename = $filename ?? ('backup_' . date('Ymd_His') . '.sql');
        $path = $dir . '/' . basename($filename);
        file_put_contents($path, $sql);
        return $path;
    }
}
