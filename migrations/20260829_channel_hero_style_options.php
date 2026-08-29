<?php
/** 页面标题区版式参数：背景色、遮罩、高度、对齐与文字色调。 */

declare(strict_types=1);

return [
    'id' => '20260829_channel_hero_style_options',
    'title' => '页面标题区版式参数',
    'desc' => '为栏目增加页面标题区版式参数，并支持随父栏目样式一同继承。',
    'title_en' => 'Page title area style options',
    'title_ja' => 'ページタイトル領域のスタイル設定',
    'desc_en' => 'Adds layout options for page title areas and includes them in parent style inheritance.',
    'desc_ja' => 'ページタイトル領域のレイアウト設定を追加し、親からのスタイル継承に含めます。',
    'check' => static fn(): bool => _columnExists('channels', 'hero_style_options'),
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `hero_style_options` longtext NULL COMMENT '页面标题区版式参数JSON' AFTER `show_hero`",
    ],
];
