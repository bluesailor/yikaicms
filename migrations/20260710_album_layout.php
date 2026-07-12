<?php
/**
 * albums 加 layout 字段（展示模式）
 *
 * 让相册可在后台选择前台展示模式：
 *   grid    —— 网格（等比方形缩略图，整齐划一）
 *   masonry —— 流布局（瀑布流，保留图片原始比例按列排布）
 * 默认 grid，保持升级前的既有行为。
 * 前台通过 [album-<id>] 短码或 type=album 栏目调用时按此模式渲染。
 */

declare(strict_types=1);

return [
    'id'    => '20260710_album_layout',
    'title' => '相册展示模式',
    'desc'  => '为 yikai_albums 表新增 layout 字段（varchar，默认 grid），让相册可在后台选择网格 / 流布局（瀑布流）展示模式。',
    'check' => function (): bool {
        return _columnExists('albums', 'layout');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "albums` ADD COLUMN `layout` varchar(20) NOT NULL DEFAULT 'grid' COMMENT '展示模式：grid网格 masonry流布局' AFTER `description`",
    ],
];
