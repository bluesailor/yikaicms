<?php
/** Blox 模板草稿、发布副本与导入来源。 */

declare(strict_types=1);

return [
    'id' => '20260806_blox_templates',
    'title' => 'Blox 模板库',
    'desc' => '新增 section/page/header/footer 四类 Blox 模板，保存草稿、发布副本、来源和依赖信息。',
    // 站点语言非中文时用这几项；Migrator::label() 取不到会回落上面的中文原文
    'title_en' => 'Blox template library',
    'title_ja' => 'Blox テンプレートライブラリ',
    'desc_en' => 'Adds the tables behind the Blox template library.',
    'desc_ja' => 'Blox テンプレートライブラリ用のテーブルを追加します。',
    'check' => static function (): bool {
        try {
            return db()->tableExists('blox_templates');
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_templates` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `type` varchar(20) NOT NULL COMMENT 'section/page/header/footer',
            `name` varchar(150) NOT NULL,
            `source` varchar(30) NOT NULL DEFAULT 'user' COMMENT 'user/import/builtin/plugin',
            `source_ref` varchar(100) NOT NULL DEFAULT '',
            `schema_version` int(11) unsigned NOT NULL DEFAULT 1,
            `draft_data` longtext NOT NULL,
            `published_data` longtext,
            `requirements` longtext,
            `thumbnail` varchar(500) NOT NULL DEFAULT '',
            `status` tinyint(1) unsigned NOT NULL DEFAULT 0,
            `admin_id` int(11) unsigned NOT NULL DEFAULT 0,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            `published_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_blox_templates_type` (`type`,`status`,`updated_at`),
            KEY `idx_blox_templates_source` (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
