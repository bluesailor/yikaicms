<?php
/**
 * channels 加 show_cover 字段
 *
 * 栏目图（channel.image）始终用作页面顶部 hero（面包屑区）的背景图。
 * 本开关控制：是否在正文顶部“再次”把该图作为头图显示一遍。
 * 默认 1（显示），保持升级前的既有行为；设为 0 时正文不再重复显示该图，
 * 图片只作顶部背景（适合总览/落地页，避免头图与卡片内容重复）。
 */

declare(strict_types=1);

return [
    'id'    => '20260711_page_show_cover',
    'title' => '单页头图显示开关',
    'desc'  => '为 yikai_channels 新增 show_cover 字段（tinyint，默认 1）：控制栏目图是否在正文顶部再次显示。默认显示，保持既有行为；设为 0 时图片只作顶部 hero 背景，正文不再重复。',
    'check' => function (): bool {
        return _columnExists('channels', 'show_cover');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `show_cover` tinyint(1) NOT NULL DEFAULT 1 COMMENT '正文顶部显示头图：1是 0否（图始终作hero背景）' AFTER `show_sidebar`",
    ],
];
