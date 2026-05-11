-- ============================================================
-- yikai_timelines / yikai_links 加 translation_group_id 字段 (2026-05-11)
--
-- 目的：让大事记（timelines）和合作伙伴（links）也能用统一的多语言翻译流，
-- 跟 channels / contents / products 一致：每个翻译组共享同一 translation_group_id。
--
-- 应用方式（任选其一）：
--   1. 在 /admin/upgrade.php 点击对应升级项（推荐，含幂等检测）
--   2. mysql -u root -p ikaicms < install/sql/upgrade_20260511_translation_group_ext.sql
--
-- 改完后管理后台 admin/timeline.php 和 admin/link.php 即可显示翻译徽标列。
-- ============================================================

-- timelines
ALTER TABLE `yikai_timelines`
    ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`,
    ADD INDEX `idx_tl_trans` (`translation_group_id`);

UPDATE `yikai_timelines`
    SET `translation_group_id` = `id`
    WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0;

-- links
ALTER TABLE `yikai_links`
    ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`,
    ADD INDEX `idx_lk_trans` (`translation_group_id`);

UPDATE `yikai_links`
    SET `translation_group_id` = `id`
    WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0;

-- banners
ALTER TABLE `yikai_banners`
    ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`,
    ADD INDEX `idx_bn_trans` (`translation_group_id`);

UPDATE `yikai_banners`
    SET `translation_group_id` = `id`
    WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0;
