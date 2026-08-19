<?php
/**
 * 设置项：网站 Logo 替代文字（site_logo_alt，默认空=回退站点名称）。
 * 配合前台就地「换Logo」对话框，可视化维护 alt。
 */

declare(strict_types=1);

return [
    'id'    => '20260819_site_logo_alt',
    'title' => '网站 Logo 替代文字设置',
    'desc'  => '新增设置项 site_logo_alt：前台 Logo 的 alt 文字，留空使用站点名称。',
    'title_en' => 'Site logo alt-text setting',
    'title_ja' => 'サイトロゴ代替テキスト設定',
    'desc_en'  => 'Adds the site_logo_alt setting: alt text for the frontend logo, falling back to the site name when empty.',
    'desc_ja'  => '設定 site_logo_alt を追加：フロントロゴの alt テキスト。空の場合はサイト名を使用します。',
    'check' => static function (): bool {
        return db()->fetchOne(
            'SELECT id FROM ' . DB_PREFIX . "settings WHERE `key` = 'site_logo_alt'"
        ) !== null;
    },
    'sqls' => [
        "INSERT INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) "
        . "VALUES ('basic', 'site_logo_alt', '', 'text', '网站Logo替代文字', '图片加载失败或读屏时显示的 alt 文字；留空使用站点名称', NULL, 6)",
    ],
];
