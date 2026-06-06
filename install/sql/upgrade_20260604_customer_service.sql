-- ============================================================
-- 在线客服浮动侧边栏（2026-06-04）
-- 数据驱动：cs_items 存 JSON 数组，支持任意数量项、自定义图标
-- ============================================================

INSERT IGNORE INTO `yikai_settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`) VALUES
  ('customer_service', 'cs_enabled',     '0',                  'switch', '启用在线客服',   '总开关，关闭后整个浮动条隐藏', 1),
  ('customer_service', 'cs_position',    'right',              'select', '显示位置',       'left=左侧 / right=右侧',       2),
  ('customer_service', 'cs_show_mobile', '1',                  'switch', '手机端显示',     '关闭后 <768px 不显示',         3),
  ('customer_service', 'cs_button_text', '在线客服',            'text',   '按钮文字',       '竖排显示',                     4),
  ('customer_service', 'cs_panel_title', '欢迎咨询，期待与您合作', 'text',   '面板标题',       '',                             5),
  ('customer_service', 'cs_items',       '[]',                 'text',   '客服项 (JSON)',  '由后台 UI 维护，每项含 type/icon/label/value/enabled', 6);

-- 清理旧版本（如有）的固定槽位 keys
DELETE FROM `yikai_settings` WHERE `key` IN (
  'cs_qq_enabled','cs_qq_label','cs_qq_value',
  'cs_wechat_enabled','cs_wechat_label','cs_wechat_qr',
  'cs_phone_enabled','cs_phone_label','cs_phone_value',
  'cs_mobile_enabled','cs_mobile_label','cs_mobile_value',
  'cs_email_enabled','cs_email_label','cs_email_value'
);
