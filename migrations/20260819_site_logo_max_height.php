<?php
/**
 * 设置项：网站 Logo 最大高度（site_logo_max_height，默认 40）。
 *
 * 前台头部 Logo 不再写死 h-10，按原始比例显示、上限由本设置控制；
 * 默认 40 与旧版观感一致，图片偏高时可按需调整（例如 60）。
 */

declare(strict_types=1);

return [
    'id'    => '20260819_site_logo_max_height',
    'title' => '网站 Logo 最大高度设置',
    'desc'  => '新增设置项 site_logo_max_height（默认 40）：前台头部 Logo 按原始比例显示的高度上限。',
    'title_en' => 'Site logo max-height setting',
    'title_ja' => 'サイトロゴ最大高さ設定',
    'desc_en'  => 'Adds the site_logo_max_height setting (default 40): height cap for the frontend header logo shown at natural ratio.',
    'desc_ja'  => '設定 site_logo_max_height（既定 40）を追加：フロントヘッダーのロゴを元比率で表示する際の高さ上限です。',
    'check' => static function (): bool {
        return db()->fetchOne(
            'SELECT id FROM ' . DB_PREFIX . "settings WHERE `key` = 'site_logo_max_height'"
        ) !== null;
    },
    'sqls' => [
        "INSERT INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) "
        . "VALUES ('basic', 'site_logo_max_height', '40', 'number', '网站Logo最大高度(px)', 'Logo 按原始比例显示，高度不超过此值（默认 40 与旧版一致；如图片偏高可调，例如 60）', NULL, 5)",
    ],
];
