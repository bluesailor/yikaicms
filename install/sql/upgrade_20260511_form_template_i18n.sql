-- ============================================================
-- yikai_form_templates 加 per-lang 列 (2026-05-11)
--
-- 目的：让表单模板的"显示名称 / 字段模板 / 成功提示"可以按语言存翻译。
-- slug 与 status 不分语言（slug 是短码 ID，跨语言用同一个）。
--
-- 应用方式（任选其一）：
--   1. 在 /admin/upgrade.php 点击对应升级项（推荐，含幂等检测）
--   2. mysql -u root -p ikaicms < install/sql/upgrade_20260511_form_template_i18n.sql
-- ============================================================

ALTER TABLE `yikai_form_templates`
    ADD COLUMN `name_en`            VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'EN 名称' AFTER `name`,
    ADD COLUMN `name_ja`            VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'JA 名称' AFTER `name_en`,
    ADD COLUMN `fields_en`          TEXT                  NULL              COMMENT 'EN 字段模板' AFTER `fields`,
    ADD COLUMN `fields_ja`          TEXT                  NULL              COMMENT 'JA 字段模板' AFTER `fields_en`,
    ADD COLUMN `success_message_en` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'EN 成功提示' AFTER `success_message`,
    ADD COLUMN `success_message_ja` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'JA 成功提示' AFTER `success_message_en`;
