-- ============================================================
-- Yikai CMS - 文章系统合并迁移脚本
-- 将 yikai_articles + yikai_article_categories 合并到
-- yikai_contents + yikai_channels
-- ============================================================
-- 执行前请备份数据库！
-- ============================================================

-- 步骤1: 将 article_categories 迁移为 channels 表中的子栏目
-- 找到 news 栏目的 ID 作为父级
-- 注意：此脚本需要通过 PHP 执行，因为需要动态获取 news 栏目 ID

-- 步骤2: 将 articles 数据迁移到 contents 表
-- 映射：articles.category_id -> contents.channel_id (通过分类迁移后的新ID)

-- 此文件仅为参考，实际迁移通过 PHP 脚本执行
-- 请运行 /admin/upgrade.php 的文章合并功能
