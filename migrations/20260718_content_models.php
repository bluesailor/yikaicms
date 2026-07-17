<?php
/**
 * 自定义内容模型：新增 content_models 表。
 * 一个模型 = 一种自定义内容类型（team/solution/faq…），内容仍存 contents(type=model_key)。
 * 见 yikaicms-docs/design-custom-content-model.md。
 */

declare(strict_types=1);

return [
    'id'    => '20260718_content_models',
    'title' => '自定义内容模型：content_models 表',
    'desc'  => '新增 yikai_content_models（模型定义：key/名称三语/图标/URL前缀/列表详情模板/有无详情）。字段定义复用 extfields(owner_type=model_key)，字段值走 metas，内容行进 contents(type=model_key)。',
    'check' => function (): bool {
        try {
            return db()->tableExists('content_models');
        } catch (\Throwable $e) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "content_models` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `model_key` varchar(32) NOT NULL COMMENT '唯一标识，标签与 owner_type 用它',
            `name` varchar(100) NOT NULL COMMENT '显示名',
            `name_en` varchar(100) NOT NULL DEFAULT '',
            `name_ja` varchar(100) NOT NULL DEFAULT '',
            `icon` varchar(255) NOT NULL DEFAULT '' COMMENT 'SVG path 或图标 key',
            `url_prefix` varchar(32) NOT NULL DEFAULT '' COMMENT '详情 URL 前缀，空=用 model_key',
            `list_template` varchar(64) NOT NULL DEFAULT '',
            `detail_template` varchar(64) NOT NULL DEFAULT '',
            `has_detail` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=纯列表无独立详情',
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_model_key` (`model_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
];
