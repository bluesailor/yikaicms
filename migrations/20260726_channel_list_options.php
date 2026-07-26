<?php
/**
 * 栏目：新增 list_options 列（列表显示元素配置）。
 *
 * 文章列表类栏目可按需隐藏列表卡片元素（封面图/摘要/作者/发布时间/浏览次数/分类名），
 * 如「常见问题」列表通常不需要封面图。存 JSON 数组（勾选显示的元素键）；
 * 空/NULL = 全部显示（向后兼容，存量栏目行为不变）。
 */

declare(strict_types=1);

return [
    'id'    => '20260726_channel_list_options',
    'title' => '栏目：列表显示元素配置',
    'desc'  => '为 yikai_channels 新增 list_options 列，文章列表类栏目可配置列表卡片显示哪些元素（封面/摘要/作者/时间/浏览数/分类名）；空值 = 全显示，存量栏目不受影响。',
    'check' => function (): bool {
        return _columnExists('channels', 'list_options');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `list_options` varchar(255) DEFAULT ''",
    ],
];
