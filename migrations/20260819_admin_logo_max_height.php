<?php
/**
 * 设置项：后台 Logo 最大高度（admin_logo_max_height，默认 80）。
 *
 * 侧栏 Logo 不再写死高度，按原始比例显示、上限由本设置控制——
 * 图片偏高时可按观感调小（例如 60）。渲染端缺行时回退默认 80，
 * 本迁移补齐设置行使其出现在「基本设置 → 后台品牌」可视化调整。
 */

declare(strict_types=1);

return [
    'id'    => '20260819_admin_logo_max_height',
    'title' => '后台 Logo 最大高度设置',
    'desc'  => '新增设置项 admin_logo_max_height（默认 80）：后台侧栏 Logo 按原始比例显示的高度上限。',
    'title_en' => 'Admin logo max-height setting',
    'title_ja' => '管理画面ロゴ最大高さ設定',
    'desc_en'  => 'Adds the admin_logo_max_height setting (default 80): height cap for the sidebar logo shown at natural ratio.',
    'desc_ja'  => '設定 admin_logo_max_height（既定 80）を追加：サイドバーロゴを元比率で表示する際の高さ上限です。',
    'check' => static function (): bool {
        return db()->fetchOne(
            'SELECT id FROM ' . DB_PREFIX . "settings WHERE `key` = 'admin_logo_max_height'"
        ) !== null;
    },
    'sqls' => [
        "INSERT INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) "
        . "VALUES ('basic', 'admin_logo_max_height', '80', 'number', '后台Logo最大高度(px)', 'Logo 按原始比例显示，高度不超过此值（如图片偏高可调小，例如 60）', NULL, 22)",
    ],
];
