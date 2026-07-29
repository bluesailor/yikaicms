<?php
/**
 * 构建器列补齐：contents 表 content_type / blocks_data。
 *
 * 这两列自构建器上线起就在 install SQL 里，但一直没有配套迁移——
 * 从构建器之前版本升级上来的老站缺列：构建器高级编辑（page_edit_advance）
 * 保存必报 Unknown column；_inline_upgrades 的 *_sample 示例种子也因写
 * content_type 而失败（cile.cn 1.9.2→1.13.2 升级首次暴露）。
 */

declare(strict_types=1);

return [
    'id'    => '20260729_builder_columns',
    'title' => '构建器：contents 补 content_type / blocks_data 列',
    'desc'  => '为老站补齐构建器所需的 yikai_contents.content_type（html/blocks）与 blocks_data（排版 JSON）两列；全新安装已含，此迁移仅老站升级需要。',
    'check' => function (): bool {
        return _columnExists('contents', 'content_type') && _columnExists('contents', 'blocks_data');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "contents` ADD COLUMN `content_type` varchar(10) NOT NULL DEFAULT 'html' COMMENT '内容类型：html/blocks' AFTER `content`",
        "ALTER TABLE `" . DB_PREFIX . "contents` ADD COLUMN `blocks_data` longtext COMMENT '排版模式JSON数据' AFTER `content_type`",
    ],
];
