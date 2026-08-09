<?php
/**
 * lang 列默认值纠偏：channels / contents / products 三表的 `lang` 出厂默认值
 * 历史上是 'ja'（其余 9 张多语言表都是 'zh-CN'）。
 *
 * 后果：任何没有显式写 lang 的新建路径，行都会落进日语桶——中文站后台
 * 新建栏目后列表页查不到（列表按 view-lang 过滤）。1.17.1 已把各页面的
 * 新建分支补上显式 lang，本迁移是第二道防线：把列默认值也纠正过来，
 * 免得将来任何新代码路径再次踩中。
 *
 * SQLite 不支持改列默认值（需整表重建），风险远大于收益——SQLite 站点
 * 依赖代码侧的显式 lang 即可，本迁移在 SQLite 上直接跳过。
 *
 * 不动任何既有数据：现存 lang='ja' 的行可能是真的日语内容，判不出来的
 * 一律不碰（用户确认「目前无客户建日语站」，但存量数据的语义只有站长自己
 * 清楚，产品侧不做猜测性重写）。
 */

declare(strict_types=1);

return [
    'id' => '20260809_lang_default_fix',
    'title' => 'lang 列默认值纠偏（ja → zh-CN）',
    'desc' => 'channels / contents / products 三表的 lang 出厂默认值原为 ja，与其余多语言表不一致，'
        . '导致未显式写 lang 的新建行落进日语桶、中文站列表查不到。本迁移只改列默认值，不动既有数据。',
    // 站点语言非中文时用这几项；Migrator::label() 取不到会回落上面的中文原文
    'title_en' => 'Fix lang column default (ja to zh-CN)',
    'title_ja' => 'lang 列の既定値を修正（ja → zh-CN）',
    'desc_en' => 'The lang column on channels, contents and products shipped with a default of ja, unlike every other multilingual table, so rows created without an explicit lang landed in the Japanese bucket and vanished from Chinese listings. This changes the column default only; existing data is untouched.',
    'desc_ja' => 'channels / contents / products の lang 列の既定値が ja になっており、他の多言語テーブルと異なっていました。そのため lang を明示せずに作成した行が日本語として保存され、中国語サイトの一覧から消えていました。本マイグレーションは列の既定値のみ変更し、既存データには触れません。',
    'check' => static function (): bool {
        // 已纠正 or SQLite（不适用）→ 视为完成
        try {
            if (db()->isSqlite()) {
                return true;
            }
            $row = db()->fetchOne(
                'SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [DB_PREFIX . 'channels', 'lang']
            );
            if (!$row) {
                return true;   // 表/列不存在（极老库）→ 交给其它迁移，不在此报错
            }
            return (string) ($row['d'] ?? '') !== 'ja';
        } catch (Throwable) {
            return true;       // 探测失败不阻塞升级链
        }
    },
    'sqls' => [],
    'php' => static function (): string {
        if (db()->isSqlite()) {
            return 'SQLite 不支持修改列默认值，已跳过（代码侧已显式写 lang）';
        }

        $fixed = [];
        foreach (['channels', 'contents', 'products'] as $t) {
            $table = DB_PREFIX . $t;
            try {
                if (!db()->tableExists($t)) {
                    continue;
                }
                $col = db()->fetchOne(
                    'SELECT COLUMN_TYPE AS ctype, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS d, COLUMN_COMMENT AS cmt
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, 'lang']
                );
                if (!$col || (string) ($col['d'] ?? '') !== 'ja') {
                    continue;   // 已是 zh-CN 或列不存在
                }
                // 保留原类型/可空性/注释，只换默认值——MODIFY 会重写整列定义，
                // 漏写任何一项都会静默改掉列语义。
                $type = (string) $col['ctype'] ?: 'varchar(10)';
                $null = ((string) $col['nullable'] === 'YES') ? 'NULL' : 'NOT NULL';
                $cmt  = (string) ($col['cmt'] ?? '');
                $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `lang` {$type} {$null} DEFAULT 'zh-CN'";
                if ($cmt !== '') {
                    $sql .= " COMMENT '" . str_replace("'", "''", $cmt) . "'";
                }
                db()->execute($sql);
                $fixed[] = $t;
            } catch (Throwable $e) {
                // 单表失败不影响其余：升级链不能因为一列默认值中断
                error_log('[20260809_lang_default_fix] ' . $t . ': ' . $e->getMessage());
            }
        }

        return $fixed === []
            ? 'lang 列默认值已是 zh-CN，无需改动'
            : ('已纠正列默认值：' . implode('、', $fixed) . '（既有数据未改动）');
    },
];
