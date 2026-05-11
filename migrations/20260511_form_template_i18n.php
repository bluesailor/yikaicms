<?php
/**
 * 表单模板：加 EN/JA 列
 *
 * 为 yikai_form_templates 加 name_en/ja, fields_en/ja, success_message_en/ja 共 6 个 per-lang 列，
 * 让表单模板的显示名称、字段模板、成功提示可以按语言独立存翻译。
 * slug / status 保持全局共享。
 */

declare(strict_types=1);

return [
    'id'    => '20260511_form_template_i18n',
    'title' => '表单模板：加 EN/JA 列',
    'desc'  => '为 yikai_form_templates 加 name_en/name_ja、fields_en/fields_ja、success_message_en/success_message_ja 共 6 个 per-lang 列。slug/status 保持全局共享。幂等：检测 fields_en 列已存在则跳过。',
    'check' => function (): bool {
        return _columnExists('form_templates', 'fields_en');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `name_en` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'EN 名称' AFTER `name`",
        "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `name_ja` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'JA 名称' AFTER `name_en`",
        "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `fields_en` TEXT NULL COMMENT 'EN 字段模板' AFTER `fields`",
        "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `fields_ja` TEXT NULL COMMENT 'JA 字段模板' AFTER `fields_en`",
        "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `success_message_en` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'EN 成功提示' AFTER `success_message`",
        "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `success_message_ja` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'JA 成功提示' AFTER `success_message_en`",
    ],
];
