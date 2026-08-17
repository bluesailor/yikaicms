<?php
/** Dedicated draft storage for Blox single pages. */

declare(strict_types=1);

return [
    'id' => '20260812_blox_page_drafts',
    'title' => 'Blox 单页草稿存储',
    'desc' => '新增独立的 Blox 单页草稿表，使保存草稿与发布线上内容完全分离。',
    'title_en' => 'Blox page draft storage',
    'title_ja' => 'Blox 固定ページ下書きストレージ',
    'desc_en' => 'Adds dedicated Blox page draft storage so saving a draft never changes published page content.',
    'desc_ja' => 'Blox 固定ページの下書きを独立保存し、下書き保存時に公開内容が変わらないようにします。',
    'check' => static fn (): bool => db()->tableExists('blox_page_drafts'),
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blox_page_drafts` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `page_id` int(11) unsigned NOT NULL,
            `draft_data` longtext NOT NULL,
            `admin_id` int(11) unsigned NOT NULL DEFAULT 0,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            `published_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blox_page_draft_page` (`page_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
