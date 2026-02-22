-- Page builder support (2026-02-22)
-- 单页排版编辑器：支持可视化区块编辑模式
ALTER TABLE `yikai_contents`
  ADD COLUMN `content_type` VARCHAR(10) NOT NULL DEFAULT 'html' COMMENT '内容类型：html/blocks' AFTER `content`,
  ADD COLUMN `blocks_data` LONGTEXT NULL COMMENT '排版模式JSON数据' AFTER `content_type`;
