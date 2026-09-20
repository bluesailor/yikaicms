<?php
/** Global Classes storage: reusable style classes + usage reverse index (v1.23 Phase 1). */

declare(strict_types=1);

return [
    'id' => '20260920_blox_global_classes',
    'title' => 'Blox 全局样式类存储',
    'desc' => '新增全局样式类表与用量反向索引表：元素按 ID 引用类，改名零迁移，用量统计不扫全站 JSON。',
    'title_en' => 'Blox global classes storage',
    'title_ja' => 'Blox グローバルクラスのストレージ',
    'desc_en' => 'Adds the global style class table and its usage reverse index: elements reference classes by ID, renames need no migration, and usage stats never scan site-wide JSON.',
    'desc_ja' => 'グローバルスタイルクラスの保存テーブルと使用逆引きテーブルを追加します。要素は ID で参照し、改名時の移行は不要、使用状況の集計もサイト全体の JSON を走査しません。',
    'check' => static fn (): bool => db()->tableExists('blox_global_classes') && db()->tableExists('blox_class_refs'),
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_global_classes` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `class_id` varchar(32) NOT NULL,
            `name` varchar(64) NOT NULL,
            `category` varchar(32) NOT NULL DEFAULT '',
            `settings` longtext NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'active',
            `trashed_at` int(11) unsigned NOT NULL DEFAULT 0,
            `modified` int(11) unsigned NOT NULL DEFAULT 0,
            `user_id` int(11) unsigned NOT NULL DEFAULT 0,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blox_global_class_id` (`class_id`),
            KEY `idx_blox_global_class_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_class_refs` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `class_id` varchar(32) NOT NULL,
            `doc_key` varchar(64) NOT NULL,
            `ref_count` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blox_class_ref` (`class_id`, `doc_key`),
            KEY `idx_blox_class_ref_doc` (`doc_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
