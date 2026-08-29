<?php
/** 页面标题区样式来源：本页、继承父栏目或全局。 */

declare(strict_types=1);

return [
    'id' => '20260829_channel_hero_style_source',
    'title' => '页面标题区样式来源',
    'desc' => '为栏目增加页面标题区样式来源，可选择本页设置、继承父栏目或使用全局样式。',
    'title_en' => 'Page title area style source',
    'title_ja' => 'ページタイトル領域のスタイル取得元',
    'desc_en' => 'Adds a page title area style source: this page, parent inheritance, or global style.',
    'desc_ja' => 'ページタイトル領域のスタイルを、このページ、親から継承、または全体設定から選べるようにします。',
    'check' => static function (): bool {
        return _columnExists('channels', 'hero_style_source');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `hero_style_source` varchar(20) NOT NULL DEFAULT 'self' COMMENT '页面标题区样式来源：self/parent/global' AFTER `show_hero`",
    ],
];
