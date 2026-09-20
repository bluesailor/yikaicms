<?php
/** Global queries storage: reusable loop queries + usage reverse index (v1.25 Phase 2). */

declare(strict_types=1);

return [
    'id' => '20260920_blox_global_queries',
    'title' => 'Blox 全局查询存储',
    'desc' => '新增全局查询表与用量反向索引表：循环容器按 ID 引用查询，一处修改全站生效，用量统计不扫全站 JSON。',
    'title_en' => 'Blox global queries storage',
    'title_ja' => 'Blox グローバルクエリのストレージ',
    'desc_en' => 'Adds the global query table and its usage reverse index: loop containers reference queries by ID, one edit applies site-wide, and usage stats never scan site-wide JSON.',
    'desc_ja' => 'グローバルクエリの保存テーブルと使用逆引きテーブルを追加します。ループコンテナは ID で参照し、一箇所の編集がサイト全体に反映され、使用状況の集計もサイト全体の JSON を走査しません。',
    'check' => static fn (): bool => db()->tableExists('blox_global_queries') && db()->tableExists('blox_query_refs'),
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_global_queries` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `query_id` varchar(32) NOT NULL,
            `name` varchar(64) NOT NULL,
            `query` longtext NOT NULL,
            `modified` int(11) unsigned NOT NULL DEFAULT 0,
            `user_id` int(11) unsigned NOT NULL DEFAULT 0,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blox_global_query_id` (`query_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_query_refs` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `query_id` varchar(32) NOT NULL,
            `doc_key` varchar(64) NOT NULL,
            `ref_count` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blox_query_ref` (`query_id`, `doc_key`),
            KEY `idx_blox_query_ref_doc` (`doc_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
