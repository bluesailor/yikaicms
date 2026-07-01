<?php
/**
 * channels 加 show_sidebar 字段
 *
 * 让单页（及栏目）可在后台单独控制前台是否显示侧边栏。
 * 默认 1（显示），保持升级前的既有行为：只要该页有同级/子栏目就显示侧边栏。
 * 设为 0 时该页隐藏侧边栏，正文占满宽度。
 */

declare(strict_types=1);

return [
    'id'    => '20260625_page_show_sidebar',
    'title' => '单页侧边栏开关',
    'desc'  => '为 yikai_channels 表新增 show_sidebar 字段（tinyint，默认 1），让单页可在后台单独控制前台是否显示侧边栏。默认显示，保持既有行为；设为 0 时隐藏侧边栏、正文占满宽度。',
    'check' => function (): bool {
        return _columnExists('channels', 'show_sidebar');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `show_sidebar` tinyint(1) NOT NULL DEFAULT 1 COMMENT '前台显示侧边栏：1是 0否' AFTER `is_home`",
    ],
];
