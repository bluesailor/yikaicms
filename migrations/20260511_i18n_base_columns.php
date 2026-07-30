<?php
/**
 * 多语言基础列补齐：lang / translation_group_id。
 *
 * 历史欠账：这两列是随 v1.7.2 的 i18n 铺开直接写进 install SQL 的，从来没有配套迁移。
 * 于是全新安装有、而 v1.7.1 及更早装机的老站升级上来**没有**——后续 20260511 系列
 * i18n 迁移（translation_group_ext / banners_translation_group / *_sample）与
 * album_i18n 全部会报 `no such column: lang` 而中断，整条升级链卡死。
 * cile.cn 1.3.0→1.9.2 直升时暴露过，当时只在该站本地补了一条站点专属迁移；
 * 本文件把它提升为主线迁移，让所有老站都能升上来。
 *
 * id 用 20260511 前缀是**有意**的：它必须排在同日的 translation_group_ext 之前。
 * Migrator::loadAll() 里独立文件按文件名 sort()，`_base_columns` 字典序早于
 * `_form_template_i18n` / `_translation_group_ext`，顺序成立。
 *
 * 缺哪张表跳过哪张（老库可能没有相册/品牌等后加的表，那些表的建表语句自带这两列）。
 * 全程幂等，可重复执行。
 */

declare(strict_types=1);

/** 需要这两列的表。顺序无关，逐张判存在。 */
$__i18nTables = [
    'albums', 'banners', 'brands', 'channels', 'contents', 'downloads',
    'jobs', 'links', 'product_categories', 'product_tags', 'products', 'timelines',
];

return [
    'id'    => '20260511_i18n_base_columns',
    'title' => '多语言基础列补齐（lang / translation_group_id）',
    'desc'  => '为 albums/banners/brands/channels/contents/downloads/jobs/links/product_categories/product_tags/products/timelines 补 lang + translation_group_id（缺哪补哪），并把源语言行的 translation_group_id 回填为自身 id。v1.7.1 及更早装机的站点升级必需；新装站已含，自动跳过。',

    'check' => function () use ($__i18nTables): bool {
        // 以后续 i18n 迁移实际依赖的表为准：这几张都有 lang 即视为已应用
        foreach (['banners', 'channels', 'contents', 'links', 'products', 'timelines'] as $t) {
            if (!_columnExists($t, 'lang')) {
                return false;
            }
        }
        return true;
    },

    'sqls' => [],

    'php' => function () use ($__i18nTables): string {
        $done = [];
        foreach ($__i18nTables as $t) {
            if (!db()->tableExists($t)) {
                continue;   // 老库没有这张表；将来建表时自带这两列
            }

            if (_addColumn($t, 'lang', "varchar(10) NOT NULL DEFAULT 'zh-CN' COMMENT '语言代码'")) {
                $done[] = "{$t}.lang";
            }
            if (_addColumn($t, 'translation_group_id', "int(11) unsigned NOT NULL DEFAULT 0 COMMENT '翻译组ID（同一概念跨语言共享）'")) {
                _addIndex($t, "idx_{$t}_trans", '`translation_group_id`');
                $done[] = "{$t}.translation_group_id";
            }

            // 源语言行的翻译组指向自身，否则翻译组机制无从建立关联
            db()->execute(
                'UPDATE `' . DB_PREFIX . $t . '` SET `translation_group_id` = `id`'
                . " WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0"
            );
        }

        return $done ? ('补列：' . implode(', ', $done)) : '列已齐全，无需变更';
    },
];
