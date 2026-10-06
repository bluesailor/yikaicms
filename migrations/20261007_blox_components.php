<?php
/** Blox components (v2.1): usage reverse index + publish revisions. Masters live in blox_templates (type=component). */

declare(strict_types=1);

return [
    'id' => '20261007_blox_components',
    'title' => 'Blox 组件存储',
    'desc' => '新增组件用量反向索引表与组件修订表。组件母版存放在 Blox 模板表（类型 component），已有页面不做任何转换。',
    'title_en' => 'Blox component storage',
    'title_ja' => 'Blox コンポーネントのストレージ',
    'desc_en' => 'Adds the component usage reverse index and the component revision table. Component masters are stored in the Blox template table (type component); existing pages are not converted.',
    'desc_ja' => 'コンポーネントの使用逆引きテーブルとリビジョンテーブルを追加します。マスターは Blox テンプレートテーブル（種類 component）に保存され、既存ページは変換しません。',
    'check' => static fn (): bool => db()->tableExists('blox_component_refs') && db()->tableExists('blox_component_revisions'),
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_component_refs` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `component_uuid` varchar(32) NOT NULL,
            `doc_key` varchar(64) NOT NULL,
            `ref_count` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blox_component_ref` (`component_uuid`, `doc_key`),
            KEY `idx_blox_component_ref_doc` (`doc_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_component_revisions` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `component_uuid` varchar(32) NOT NULL,
            `version` int(11) unsigned NOT NULL DEFAULT 0,
            `snapshot` longtext NOT NULL,
            `admin_id` int(11) unsigned NOT NULL DEFAULT 0,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_blox_component_rev` (`component_uuid`, `version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
