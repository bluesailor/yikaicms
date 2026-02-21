-- 表单模板（短码表单系统）
-- 2026-02-19

CREATE TABLE IF NOT EXISTS `yikai_form_templates` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL COMMENT '表单名称',
    `slug` varchar(50) NOT NULL COMMENT '短码标识',
    `fields` text COMMENT '字段配置JSON',
    `success_message` varchar(255) NOT NULL DEFAULT '提交成功，感谢您的反馈！' COMMENT '成功提示',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表单模板';

-- 默认联系表单
INSERT INTO `yikai_form_templates` (`name`, `slug`, `fields`, `success_message`, `status`, `created_at`)
VALUES (
    '联系表单',
    'contact',
    '[{"key":"name","label":"姓名","type":"text","required":true,"placeholder":"请输入姓名"},{"key":"phone","label":"电话","type":"tel","required":true,"placeholder":"请输入电话"},{"key":"email","label":"邮箱","type":"email","required":false,"placeholder":"请输入邮箱"},{"key":"company","label":"公司","type":"text","required":false,"placeholder":"请输入公司名称"},{"key":"content","label":"留言内容","type":"textarea","required":true,"placeholder":"请输入留言内容"}]',
    '提交成功，感谢您的反馈！',
    1,
    UNIX_TIMESTAMP()
);
