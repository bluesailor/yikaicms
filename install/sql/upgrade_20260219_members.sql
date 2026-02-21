-- 前台会员系统
-- 2026-02-19

-- 会员表
CREATE TABLE IF NOT EXISTS `yikai_members` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL,
    `password` varchar(255) NOT NULL,
    `email` varchar(100) NOT NULL DEFAULT '',
    `nickname` varchar(50) NOT NULL DEFAULT '',
    `avatar` varchar(255) NOT NULL DEFAULT '',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `last_login_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `last_login_ip` varchar(45) NOT NULL DEFAULT '',
    `login_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台会员表';

-- 会员设置
INSERT IGNORE INTO `yikai_settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`)
VALUES
('member', 'show_member_entry', '0', 'switch', '显示会员入口', '关闭后前台导航栏将不显示登录/注册入口', 0),
('member', 'allow_member_register', '1', 'switch', '允许会员注册', '关闭后前台将无法注册新会员', 1),
('member', 'download_require_login', '0', 'switch', '下载需要登录', '开启后下载文件需要会员登录', 2);
