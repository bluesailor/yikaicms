<?php
declare(strict_types=1);

return [
    'id' => '20260914_dologin_links',
    'title' => 'Easy Login temporary links',
    'desc' => 'Store one-time admin login verifiers and redemption history.',
    'check' => static fn(): bool => db()->tableExists('dologin_links'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'dologin_links` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) unsigned NOT NULL,
            `created_by` int(11) unsigned NOT NULL,
            `token_hash` varchar(64) NOT NULL,
            `identity_hash` varchar(64) NOT NULL,
            `note` varchar(200) NOT NULL DEFAULT \'\',
            `created_at` int(11) NOT NULL,
            `expires_at` int(11) NOT NULL,
            `used_at` int(11) NOT NULL DEFAULT 0,
            `revoked_at` int(11) NOT NULL DEFAULT 0,
            `used_ip` varchar(45) NOT NULL DEFAULT \'\',
            PRIMARY KEY (`id`),
            UNIQUE KEY `dologin_token` (`token_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
];
