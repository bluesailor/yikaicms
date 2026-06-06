-- ============================================================
-- 删除已弃用的 yikai_articles / yikai_article_categories（2026-06-04）
-- 文章系统已合并到 yikai_contents（type='article'） + yikai_channels
-- 旧表在新版 schema 中已移除，本脚本用于升级既有安装时清理残留表。
-- 执行前请先确认旧表无数据，或已通过 migrate_articles_to_contents 迁出。
-- ============================================================

DROP TABLE IF EXISTS `yikai_articles`;
DROP TABLE IF EXISTS `yikai_article_categories`;
