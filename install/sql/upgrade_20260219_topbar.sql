-- 顶部通栏设置 (2026-02-19)
INSERT IGNORE INTO `yikai_settings` (`key`, `name`, `value`, `group`, `type`, `tip`, `options`, `sort_order`) VALUES
('topbar_enabled', '显示顶部通栏', '0', 'header', 'select', 'Logo上方的通栏区域', '{"0":"隐藏","1":"显示"}', '0'),
('topbar_bg_color', '通栏背景色', '#f3f4f6', 'header', 'color', '顶部通栏背景颜色', NULL, '1'),
('topbar_left', '通栏左侧内容', '', 'header', 'code', '支持HTML代码，如电话、公告等', NULL, '2');

-- 调整原页头设置排序
UPDATE `yikai_settings` SET `sort_order` = 10 WHERE `key` = 'header_nav_layout';
UPDATE `yikai_settings` SET `sort_order` = 11 WHERE `key` = 'header_sticky';
UPDATE `yikai_settings` SET `sort_order` = 12 WHERE `key` = 'header_bg_color';
UPDATE `yikai_settings` SET `sort_order` = 13 WHERE `key` = 'header_text_color';
