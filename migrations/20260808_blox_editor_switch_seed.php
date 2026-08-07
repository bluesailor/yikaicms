<?php
/** Blox 编辑器开关设置行补种（老库升级）。 */

declare(strict_types=1);

return [
    'id' => '20260808_blox_editor_switch_seed',
    'title' => 'Blox 编辑器开关设置行',
    'desc' => '补入 blox_editor_enabled 设置行（默认关闭）。此前该键仅存在于 defaults 声明，无种子行且无任何后台页面渲染，用户无法开启 Blox。',
    'check' => static function (): bool {
        try {
            $row = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . "settings WHERE `key` = 'blox_editor_enabled'");
            return is_array($row);
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "INSERT INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`)"
        . " VALUES ('system', 'blox_editor_enabled', '0', 'switch', 'Blox 编辑器', '开启后在栏目管理、单页管理与排版编辑器中显示 Blox 入口', NULL, 3)",
    ],
];
