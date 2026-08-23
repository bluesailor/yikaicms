<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class DatabaseMaintenance
{
    /** @return array{statements:int,errors:list<string>} */
    public static function restoreSql(string $sql): array
    {
        $statements = self::splitSql($sql);
        return db()->isSqlite()
            ? self::restoreSqlite($statements)
            : self::restoreMysql($statements);
    }

    /** @param list<string> $tables */
    public static function clearTables(array $tables): int
    {
        $cleared = 0;
        foreach ($tables as $table) {
            self::assertTableName($table);
            if (!db()->tableExists($table)) {
                continue;
            }
            $full = DB_PREFIX . $table;
            $cleared += (int) db()->fetchColumn("SELECT COUNT(*) FROM `{$full}`");
            if (db()->isSqlite()) {
                db()->execute("DELETE FROM `{$full}`");
                if (db()->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'") !== null) {
                    db()->execute('DELETE FROM sqlite_sequence WHERE name = ?', [$full]);
                }
            } else {
                db()->execute("TRUNCATE TABLE `{$full}`");
            }
        }
        return $cleared;
    }

    /** @param list<string> $tables */
    public static function optimize(array $tables): int
    {
        if (db()->isSqlite()) {
            db()->execute('PRAGMA optimize');
            return count($tables);
        }
        foreach ($tables as $table) {
            self::assertTableName($table);
            db()->execute('OPTIMIZE TABLE `' . DB_PREFIX . $table . '`');
        }
        return count($tables);
    }

    /** @return list<string> */
    public static function splitSql(string $sql): array
    {
        $result = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= ' ';
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                    $buffer .= ' ';
                }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote && $quote !== '`') {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                $lineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $lineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $result[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $statement = trim($buffer);
        if ($statement !== '') {
            $result[] = $statement;
        }
        return $result;
    }

    /** @param list<string> $statements @return array{statements:int,errors:list<string>} */
    private static function restoreSqlite(array $statements): array
    {
        $count = 0;
        db()->execute('PRAGMA foreign_keys = OFF');
        db()->beginTransaction();
        try {
            foreach ($statements as $statement) {
                if (preg_match('/^(?:BEGIN|COMMIT|ROLLBACK|PRAGMA\s+foreign_keys)\b/i', $statement)) {
                    continue;
                }
                db()->execute($statement);
                $count++;
            }
            db()->commit();
            return ['statements' => $count, 'errors' => []];
        } catch (Throwable $e) {
            db()->rollback();
            return ['statements' => 0, 'errors' => [mb_substr($e->getMessage(), 0, 200)]];
        } finally {
            db()->execute('PRAGMA foreign_keys = ON');
        }
    }

    /** @param list<string> $statements @return array{statements:int,errors:list<string>} */
    private static function restoreMysql(array $statements): array
    {
        $count = 0;
        $errors = [];
        db()->execute('SET NAMES utf8mb4');
        db()->execute('SET autocommit = 0');
        db()->execute('SET unique_checks = 0');
        db()->execute('SET foreign_key_checks = 0');
        try {
            foreach ($statements as $statement) {
                try {
                    db()->execute($statement);
                    $count++;
                    if ($count % 200 === 0) {
                        db()->execute('COMMIT');
                    }
                } catch (Throwable $e) {
                    if (count($errors) < 10) {
                        $errors[] = mb_substr($e->getMessage(), 0, 200);
                    }
                }
            }
            db()->execute('COMMIT');
        } finally {
            db()->execute('SET unique_checks = 1');
            db()->execute('SET foreign_key_checks = 1');
            db()->execute('SET autocommit = 1');
        }
        return ['statements' => $count, 'errors' => $errors];
    }

    private static function assertTableName(string $table): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('非法表名');
        }
    }
}
