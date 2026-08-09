<?php
/** 导航菜单组：多组菜单（组名 + 项 JSON），nav-mega/nav-drawer 可选菜单来源。 */

declare(strict_types=1);

return [
    'id' => '20260808_nav_menus',
    'title' => '导航菜单组',
    'desc' => '新增 nav_menus 表：可建多组菜单（组名+项），项支持栏目引用与自定义链接、三级嵌套；导航元素可选菜单来源，不选仍走栏目投影。',
    // 站点语言非中文时用这几项；Migrator::label() 取不到会回落上面的中文原文
    'title_en' => 'Custom navigation menus',
    'title_ja' => 'カスタムナビゲーションメニュー',
    'desc_en' => 'Adds the nav_menus table so footer columns can use custom menu groups with headings.',
    'desc_ja' => 'nav_menus テーブルを追加し、フッターの各カラムで見出し付きのカスタムメニューを使えるようにします。',
    'check' => static function (): bool {
        try {
            return db()->tableExists('nav_menus');
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "nav_menus` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `items` longtext,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `created_at` int(11) unsigned NOT NULL DEFAULT 0,
            `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
