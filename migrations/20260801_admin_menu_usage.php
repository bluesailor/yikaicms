<?php
declare(strict_types=1);

return [
    'id' => '20260801_admin_menu_usage',
    'title' => 'Admin menu usage history',
    'desc' => 'Add per-admin backend menu usage table for dashboard recent links.',
    // 站点语言非中文时用这几项；Migrator::label() 取不到会回落上面的中文原文
    'title_en' => 'Admin menu usage tracking',
    'title_ja' => '管理メニューの利用状況',
    'desc_en' => 'Adds a table that records which admin menu items you use, to power the recently-used shortcuts.',
    'desc_ja' => '「最近使用した項目」ショートカットのために、管理メニューの利用状況を記録するテーブルを追加します。',
    'check' => function (): bool {
        try {
            return db()->tableExists('admin_menu_usage');
        } catch (\Throwable $e) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "admin_menu_usage` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `admin_id` int(11) unsigned NOT NULL,
            `url` varchar(255) NOT NULL,
            `title` varchar(120) NOT NULL DEFAULT '',
            `icon` varchar(80) NOT NULL DEFAULT '',
            `used_count` int(11) unsigned NOT NULL DEFAULT 0,
            `last_used_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_admin_url` (`admin_id`, `url`),
            KEY `idx_admin_last` (`admin_id`, `last_used_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
