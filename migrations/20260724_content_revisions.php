<?php
/**
 * 内容版本历史：content_revisions 表。
 *
 * 「保存即存档」——文章 / 单页每次保存前，把被覆盖的旧版本快照存入本表；
 * 编辑页可查看最近若干版本并一键恢复（恢复本身也会先存档当前版，可再退回）。
 * 每个目标默认保留最近 5 版（config('revision_keep', 5)），超出自动清理。
 *
 * target_type：'article'（contents.id）或 'page'（channels.id）。
 * snapshot：JSON = {"targets":[{"table":"contents","id":180,"fields":{...}}, ...]}。
 */

declare(strict_types=1);

return [
    'id'    => '20260724_content_revisions',
    'title' => '内容版本历史：content_revisions 表',
    'desc'  => '新增 yikai_content_revisions（文章/单页保存即存档，支持查看最近版本并一键恢复）。',
    'check' => function (): bool {
        try {
            return db()->tableExists('content_revisions');
        } catch (\Throwable $e) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "content_revisions` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `target_type` varchar(20) NOT NULL COMMENT 'article=contents.id / page=channels.id',
            `target_id` int(11) unsigned NOT NULL COMMENT '归属对象ID',
            `lang` varchar(10) NOT NULL DEFAULT '' COMMENT '语言',
            `snapshot` longtext COMMENT '旧版快照JSON {targets:[{table,id,fields}]}',
            `summary` varchar(255) NOT NULL DEFAULT '' COMMENT '列表展示用（通常为标题）',
            `admin_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作人ID',
            `admin_name` varchar(50) NOT NULL DEFAULT '' COMMENT '操作人名',
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_target` (`target_type`,`target_id`,`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
];
