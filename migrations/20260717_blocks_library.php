<?php
/**
 * 页面构建器 P2：可复用块库 blocks_library 表。
 * 存命名区块（单个 section 的 JSON），页面 blocks_data 里以 {library_id} 引用，
 * 渲染时展开——库里改一处，所有引用页面全站生效。见 yikaicms-docs/design-page-builder.md 2.5。
 */

declare(strict_types=1);

return [
    'id'    => '20260717_blocks_library',
    'title' => '页面构建器：可复用块 blocks_library 表',
    'desc'  => '新增 yikai_blocks_library（命名可复用区块：name + 单 section JSON）。页面区块以 library_id 引用，BlockRenderer 渲染时从库展开，改库内容全站生效。',
    'check' => function (): bool {
        try {
            return db()->tableExists('blocks_library');
        } catch (\Throwable $e) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blocks_library` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL COMMENT '块名称（后台块库列表显示）',
            `data` longtext COMMENT '单个 section 的 JSON（settings+columns）',
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
];
