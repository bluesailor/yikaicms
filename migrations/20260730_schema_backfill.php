<?php
/**
 * 结构补齐（第二批）：install SQL 一直有、却从无配套迁移的表与列。
 *
 * 由 tests/smoke/schema_parity.php 的双向对拍一次性扫出。这些缺口在老站上表现为
 * 功能整块失灵而不是报错退出，很难被发现：
 *   - extfields / metas 两张表缺失 → **扩展字段整套功能在升级上来的站点上不可用**
 *     （字段定义存 extfields、字段值存 metas；两表都只在 install SQL 里建过）
 *   - banner_groups.fullscreen → 全屏大 Banner 开关保存即报 Unknown column
 *   - form_templates.captcha   → 表单图形验证码开关同上
 *   - products.product_type    → 产品预置参数（v1.12.10）保存同上
 *
 * 全部判存在后再动，幂等可重跑。新装站直接跳过。
 */

declare(strict_types=1);

/** 缺失的列：表 => [列 => MySQL 列定义]。 */
$__bf_cols = [
    'banner_groups'  => ['fullscreen'   => "tinyint(1) NOT NULL DEFAULT 0 COMMENT '全屏大Banner（PC满屏高度）'"],
    'form_templates' => ['captcha'      => "tinyint(1) NOT NULL DEFAULT 0 COMMENT '启用图形验证码'"],
    'products'       => ['product_type' => "varchar(20) NOT NULL DEFAULT 'custom' COMMENT '产品类型'"],
];

return [
    'id'    => '20260730_schema_backfill',
    'title' => '结构补齐：扩展字段两表 + 3 个缺失列',
    'desc'  => '建 extfields / metas（扩展字段定义与值，老站从来没有过这两张表，导致扩展字段功能整块不可用）；补 banner_groups.fullscreen、form_templates.captcha、products.product_type。判存在，幂等。',

    'check' => function () use ($__bf_cols): bool {
        if (!db()->tableExists('extfields') || !db()->tableExists('metas')) {
            return false;
        }
        foreach ($__bf_cols as $t => $defs) {
            foreach (array_keys($defs) as $c) {
                if (db()->tableExists($t) && !_columnExists($t, $c)) {
                    return false;
                }
            }
        }
        return true;
    },

    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "extfields` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `owner_type` varchar(30) NOT NULL,
            `field_key` varchar(64) NOT NULL,
            `field_name` varchar(100) NOT NULL,
            `field_type` varchar(20) NOT NULL DEFAULT 'text',
            `options` text,
            `placeholder` varchar(255) NOT NULL DEFAULT '',
            `help_text` varchar(255) NOT NULL DEFAULT '',
            `is_required` tinyint(1) NOT NULL DEFAULT 0,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_owner_key` (`owner_type`,`field_key`),
            KEY `idx_owner` (`owner_type`,`status`,`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='扩展字段定义表'",

        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "metas` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `owner_type` varchar(30) NOT NULL COMMENT '归属类型',
            `owner_id` int(11) unsigned NOT NULL DEFAULT 0,
            `meta_key` varchar(100) NOT NULL,
            `meta_value` longtext,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_owner_key` (`owner_type`,`owner_id`,`meta_key`),
            KEY `idx_owner` (`owner_type`,`owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用元数据键值表'",
    ],

    'php' => function () use ($__bf_cols): string {
        // 建表语句里的 KEY 子句在 SQLite 上会被 _sqlToSqlite() 丢掉，索引另行补
        _addIndex('extfields', 'idx_extfields_owner', '`owner_type`, `status`, `sort_order`');
        _addIndex('metas', 'idx_metas_owner', '`owner_type`, `owner_id`');

        $added = [];
        foreach ($__bf_cols as $t => $defs) {
            if (!db()->tableExists($t)) {
                continue;
            }
            foreach ($defs as $c => $def) {
                if (_addColumn($t, $c, $def)) {
                    $added[] = "$t.$c";
                }
            }
        }
        return $added ? ('补列：' . implode(', ', $added)) : '表已建齐，无缺列';
    },
];
