<?php
/**
 * MySQL 安装包 SQL → SQLite 兼容 SQL 转换器。
 *
 * 用法：
 *   php tools/mysql_to_sqlite.php install/sql/mysql.sql install/sql/sqlite.sql
 *
 * 转换规则（参考 admin/upgrade.php::_sqlToSqlite + install/index.php 的 SQLite 安装路径）：
 *   - DROP/CREATE TABLE：MySQL → SQLite 兼容
 *   - 反引号 → 双引号
 *   - 列定义：去 UNSIGNED / int(N)→INTEGER / AUTO_INCREMENT 列变 PRIMARY KEY AUTOINCREMENT
 *   - 表后 KEY/UNIQUE KEY 拆出来变成单独的 CREATE [UNIQUE] INDEX
 *   - PRIMARY KEY (`id`) 行：自增列时丢弃（已 inline 到列定义）
 *   - ENGINE / CHARSET / COMMENT 全部丢弃
 *   - INSERT：反引号→双引号；其它保持
 *   - SET FOREIGN_KEY_CHECKS = 0/1 → SQLite 用 PRAGMA foreign_keys = OFF/ON
 *   - SET NAMES utf8mb4 → 注释掉
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/mysql_to_sqlite.php <input.sql> <output.sql>\n");
    exit(1);
}

$in  = $argv[1];
$out = $argv[2];

if (!file_exists($in)) {
    fwrite(STDERR, "Input not found: $in\n");
    exit(1);
}

$src = file_get_contents($in);

// 规范化行尾（mysqldump 在 Windows 下可能输出 CRLF）
$src = str_replace(["\r\n", "\r"], "\n", $src);

// 头注释保留
$lines = explode("\n", $src);
$result = [];
$i = 0;
$N = count($lines);

while ($i < $N) {
    $line = $lines[$i];

    // 跳过 mysqldump 的特殊语句
    if (preg_match('/^\/\*!\d+ SET/i', $line) || preg_match('/^\s*\/\*!/i', $line)) {
        $i++;
        continue;
    }
    if (preg_match('/^\s*SET NAMES /i', $line) || preg_match('/^\s*SET character_set_client/i', $line)) {
        $i++;
        continue;
    }
    // LOCK / UNLOCK TABLES：SQLite 不支持
    if (preg_match('/^\s*(LOCK|UNLOCK) TABLES/i', $line)) {
        $i++;
        continue;
    }
    if (preg_match('/SET FOREIGN_KEY_CHECKS\s*=\s*0/i', $line)) {
        $result[] = 'PRAGMA foreign_keys = OFF;';
        $i++;
        continue;
    }
    if (preg_match('/SET FOREIGN_KEY_CHECKS\s*=\s*1/i', $line)) {
        $result[] = 'PRAGMA foreign_keys = ON;';
        $i++;
        continue;
    }

    // DROP TABLE
    if (preg_match('/^DROP TABLE IF EXISTS `(\w+)`;/i', $line, $m)) {
        $result[] = "DROP TABLE IF EXISTS \"{$m[1]}\";";
        $i++;
        continue;
    }

    // CREATE TABLE
    if (preg_match('/^CREATE TABLE `(\w+)`\s*\($/i', $line, $m)) {
        $tableName = $m[1];
        $colLines = [];
        $indexes = [];
        $autoIncCol = null;
        $i++;
        while ($i < $N) {
            $l = rtrim($lines[$i]);
            if (preg_match('/^\)\s*(ENGINE=|DEFAULT|;)/i', $l)) {
                $i++;
                break;
            }
            $l = trim($l, " \t,");
            if ($l === '') { $i++; continue; }

            // FULLTEXT KEY：SQLite 不支持，丢弃（如需全文搜索另用 FTS 虚拟表）
            if (preg_match('/^FULLTEXT KEY\s+/i', $l)) {
                $i++; continue;
            }
            // KEY `name` (`col1`, `col2`)
            // SQLite 索引名全局唯一（不像 MySQL 表内唯一），加表名后缀避免重名
            if (preg_match('/^KEY\s+`(\w+)`\s*\(([^)]+)\)$/i', $l, $km)) {
                $cols = preg_replace('/`/', '"', $km[2]);
                $idxName = $km[1] . '_' . $tableName;
                $indexes[] = "CREATE INDEX \"{$idxName}\" ON \"{$tableName}\" ($cols);";
                $i++; continue;
            }
            // UNIQUE KEY `name` (`col`)
            if (preg_match('/^UNIQUE KEY\s+`(\w+)`\s*\(([^)]+)\)$/i', $l, $km)) {
                $cols = preg_replace('/`/', '"', $km[2]);
                $idxName = $km[1] . '_' . $tableName;
                $indexes[] = "CREATE UNIQUE INDEX \"{$idxName}\" ON \"{$tableName}\" ($cols);";
                $i++; continue;
            }
            // PRIMARY KEY (`id`) —— 单列，若是自增已 inline，跳过
            if (preg_match('/^PRIMARY KEY\s*\(`(\w+)`\)$/i', $l, $pm)) {
                if ($autoIncCol === $pm[1]) {
                    // 已通过列内 AUTOINCREMENT 标注，丢弃此行
                } else {
                    $colLines[] = "  PRIMARY KEY (\"{$pm[1]}\")";
                }
                $i++; continue;
            }
            // PRIMARY KEY (`col1`, `col2`, ...) —— 复合主键
            if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)$/i', $l, $pm)) {
                $cols = preg_replace('/`/', '"', $pm[1]);
                $colLines[] = "  PRIMARY KEY ($cols)";
                $i++; continue;
            }

            // 列定义：`name` type [UNSIGNED] [NOT NULL] [DEFAULT 'x'] [AUTO_INCREMENT] [COMMENT 'y']
            if (preg_match('/^`(\w+)`\s+(.+)$/i', $l, $cm)) {
                $name = $cm[1];
                $rest = $cm[2];

                // 去 COMMENT
                $rest = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $rest);
                // int(N)/bigint(N)/tinyint(N)/smallint(N) → INTEGER
                $rest = preg_replace('/\b(?:big|small|tiny|medium)?int\(\d+\)/i', 'INTEGER', $rest);
                // UNSIGNED 去掉
                $rest = preg_replace('/\s+unsigned\b/i', '', $rest);
                // varchar(N) / char(N) → TEXT；text / longtext / mediumtext → TEXT
                $rest = preg_replace('/\b(?:var)?char\(\d+\)/i', 'TEXT', $rest);
                $rest = preg_replace('/\b(?:long|medium|tiny)?text\b/i', 'TEXT', $rest);
                // decimal/double/float
                $rest = preg_replace('/\bdecimal\([^)]+\)/i', 'REAL', $rest);
                $rest = preg_replace('/\bdouble\b/i', 'REAL', $rest);
                $rest = preg_replace('/\bfloat\b/i', 'REAL', $rest);
                // datetime / timestamp → TEXT
                $rest = preg_replace('/\bdatetime\b/i', 'TEXT', $rest);
                $rest = preg_replace('/\btimestamp\b/i', 'TEXT', $rest);
                // CURRENT_TIMESTAMP 默认值
                $rest = preg_replace("/DEFAULT CURRENT_TIMESTAMP/i", "DEFAULT (datetime('now'))", $rest);
                // tinyint(1) 已被 INTEGER 替换；保留
                // AUTO_INCREMENT：列变 PRIMARY KEY AUTOINCREMENT
                if (preg_match('/AUTO_INCREMENT/i', $rest)) {
                    $autoIncCol = $name;
                    // SQLite 要求 INTEGER PRIMARY KEY AUTOINCREMENT
                    $rest = preg_replace('/\bINTEGER\s+NOT\s+NULL\s+AUTO_INCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $rest);
                    $rest = preg_replace('/\bINTEGER\s+AUTO_INCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $rest);
                    $rest = preg_replace('/\bAUTO_INCREMENT\b/i', '', $rest);
                }
                // 多余空格
                $rest = preg_replace('/\s+/', ' ', $rest);
                $rest = trim($rest);

                $colLines[] = "  \"{$name}\" {$rest}";
                $i++; continue;
            }

            // 默认：原样保留
            $colLines[] = "  $l";
            $i++;
        }

        $result[] = "CREATE TABLE \"{$tableName}\" (";
        $result[] = implode(",\n", $colLines);
        $result[] = ");";
        foreach ($indexes as $idx) $result[] = $idx;
        $result[] = '';
        continue;
    }

    // INSERT INTO `table` (`col1`, `col2`, ...) VALUES (...);
    // MySQL 的 INSERT IGNORE → SQLite 的 INSERT OR IGNORE（如 contact 地图设置的幂等种子）
    $line = preg_replace('/^INSERT IGNORE INTO /i', 'INSERT OR IGNORE INTO ', $line);
    if (preg_match('/^INSERT (?:OR IGNORE )?INTO `(\w+)`/i', $line)) {
        // 反引号 → 双引号
        $converted = preg_replace_callback('/`(\w+)`/', fn($m) => '"' . $m[1] . '"', $line);
        // 转换字符串字面量中的 MySQL 转义到 SQLite 兼容
        $converted = preg_replace_callback(
            "/'((?:[^'\\\\]|\\\\.)*)'/",
            function ($m) {
                $s = $m[1];
                // 占位保护 \\\\ 不被后续替换破坏
                $s = str_replace('\\\\', "\x01", $s);
                $s = str_replace("\\'", "''", $s);
                $s = str_replace('\\"', '"', $s);
                $s = str_replace('\\n', "\n", $s);
                $s = str_replace('\\r', "\r", $s);
                $s = str_replace('\\t', "\t", $s);
                $s = str_replace('\\0', "\0", $s);
                // 复原 literal backslash
                $s = str_replace("\x01", '\\', $s);
                return "'" . $s . "'";
            },
            $converted
        );
        $result[] = $converted;
        $i++; continue;
    }

    // 其它原样
    $result[] = $line;
    $i++;
}

file_put_contents($out, implode("\n", $result));
echo "OK: $out (" . count($result) . " 行)\n";
