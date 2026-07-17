<?php
/**
 * 后台两步验证（TOTP）：users 表加 totp_secret。
 * 空串 = 未启用；绑定后存 base32 密钥。见 includes/Totp.php 与 admin/profile.php。
 */

declare(strict_types=1);

return [
    'id'    => '20260717_admin_totp',
    'title' => '管理员两步验证：users 加 totp_secret',
    'desc'  => '为 yikai_users 表新增 totp_secret 列（varchar(64)，空串=未启用）。管理员在个人设置里扫码绑定后，登录需二次输入验证器 6 位码。',
    'check' => function (): bool {
        return _columnExists('users', 'totp_secret');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "users` ADD COLUMN `totp_secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'TOTP两步验证密钥（base32，空=未启用）' AFTER `password`",
    ],
];
