<?php
/** 远程 Blox 模板版本与更新前草稿保护。 */

declare(strict_types=1);

return [
    'id' => '20260829_blox_remote_template_states',
    'title' => 'Blox 远程模板更新保护',
    'desc' => '记录远程模板版本，并保留最近一次更新前的草稿以支持安全回退。',
    'title_en' => 'Blox remote template update protection',
    'title_ja' => 'Blox リモートテンプレート更新保護',
    'desc_en' => 'Tracks remote template versions and keeps the previous draft for rollback.',
    'desc_ja' => 'リモートテンプレートのバージョンと更新前の下書きを保存します。',
    'check' => static function (): bool {
        try {
            return db()->tableExists('blox_remote_template_states');
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_remote_template_states` (
            `template_id` int(11) unsigned NOT NULL,
            `installed_version` varchar(50) NOT NULL DEFAULT '',
            `backup_version` varchar(50) NOT NULL DEFAULT '',
            `backup_draft` longtext,
            `backup_requirements` longtext,
            `backup_metadata` longtext,
            `backup_created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`template_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
