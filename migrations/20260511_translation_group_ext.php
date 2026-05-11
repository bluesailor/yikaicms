<?php
/**
 * timelines / links / banners 加 translation_group_id 字段
 *
 * 让大事记、合作伙伴、轮播图也能用统一的多语言翻译流，
 * 跟 channels / contents / products 一致：每个翻译组共享同一 translation_group_id。
 * 源行（zh-CN）的 translation_group_id 顺手回填为自身 id。
 */

declare(strict_types=1);

return [
    'id'    => '20260511_translation_group_ext',
    'title' => 'timelines / links / banners 加翻译组字段',
    'desc'  => '为 yikai_timelines / yikai_links / yikai_banners 表新增 translation_group_id 字段，让大事记、合作伙伴、轮播图也能用统一的多语言翻译流（与 channels/contents/products 一致）。源行的 translation_group_id 顺手回填为自己的 id。',
    'check' => function (): bool {
        return _columnExists('timelines', 'translation_group_id')
            && _columnExists('links', 'translation_group_id')
            && _columnExists('banners', 'translation_group_id');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "timelines` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID（同一概念跨语言的多个行共享同一值）' AFTER `lang`",
        "ALTER TABLE `" . DB_PREFIX . "timelines` ADD INDEX `idx_tl_trans` (`translation_group_id`)",
        "UPDATE `" . DB_PREFIX . "timelines` SET `translation_group_id` = `id` WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0",

        "ALTER TABLE `" . DB_PREFIX . "links` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`",
        "ALTER TABLE `" . DB_PREFIX . "links` ADD INDEX `idx_lk_trans` (`translation_group_id`)",
        "UPDATE `" . DB_PREFIX . "links` SET `translation_group_id` = `id` WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0",

        "ALTER TABLE `" . DB_PREFIX . "banners` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`",
        "ALTER TABLE `" . DB_PREFIX . "banners` ADD INDEX `idx_bn_trans` (`translation_group_id`)",
        "UPDATE `" . DB_PREFIX . "banners` SET `translation_group_id` = `id` WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0",
    ],
];
