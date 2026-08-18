<?php
/**
 * channels 加 hero_bg / show_hero 字段
 *
 * 内页（面包屑+标题）横幅定制：
 * - hero_bg：本页横幅背景图，空=沿用旧链（栏目图 image → 全局默认 page_hero_default_bg → 渐变）。
 *   与 image 解耦——客户常要求「横幅背景单独换一张」而不动正文头图/列表图。
 * - show_hero：横幅整体开关，默认 1 显示；0 时整条横幅（含面包屑与标题）不渲染，
 *   适合 Blox 排版自带首屏的落地页。
 * 联系页的紧凑页头仅认显式 hero_bg（不继承 image/全局默认），避免升级改变既有观感。
 */

declare(strict_types=1);

return [
    'id'    => '20260819_channel_hero_options',
    'title' => '内页横幅背景与开关',
    'desc'  => '为 yikai_channels 新增 hero_bg（横幅背景图，空=沿用原有回退链）与 show_hero（横幅显示开关，默认显示）。',
    'title_en' => 'Per-page hero background & toggle',
    'title_ja' => 'ページヘッダー背景と表示スイッチ',
    'desc_en'  => 'Adds hero_bg (banner background, empty = legacy fallback chain) and show_hero (banner visibility, default on) to channels.',
    'desc_ja'  => 'channels に hero_bg（バナー背景、空なら従来のフォールバック）と show_hero（バナー表示、既定はオン）を追加します。',
    'check' => function (): bool {
        return _columnExists('channels', 'hero_bg') && _columnExists('channels', 'show_hero');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `hero_bg` varchar(500) NOT NULL DEFAULT '' COMMENT '内页横幅背景图：空=栏目图→全局默认→渐变' AFTER `show_cover`",
        "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `show_hero` tinyint(1) NOT NULL DEFAULT 1 COMMENT '内页横幅（面包屑+标题）显示：1是 0否' AFTER `hero_bg`",
    ],
];
