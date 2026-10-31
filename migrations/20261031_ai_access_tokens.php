<?php
/** v2.2 AI 外部访问（ADR-001/003）：访问令牌、访问日志与限流计数。只建表，不签发令牌、不打开总开关。 */

declare(strict_types=1);

return [
    'id'    => '20261031_ai_access_tokens',
    'title' => 'AI 外部访问令牌',
    'desc'  => '新增 api_tokens（只存令牌摘要，不存明文）、api_access_log（外部调用审计，不记录凭据与正文）、api_rate_limits（按分钟计数）三张表。外部访问默认关闭，需站长在后台启用并签发令牌后才可使用。',
    'title_en' => 'AI external access tokens',
    'title_ja' => 'AI 外部アクセストークン',
    'desc_en' => 'Adds api_tokens (stores token digests only, never the token itself), api_access_log (external call audit without credentials or content) and api_rate_limits (per-minute counters). External access stays off until the site owner enables it and issues a token in the admin.',
    'desc_ja' => 'api_tokens（トークンのダイジェストのみ保存し、トークン本体は保存しません）、api_access_log（認証情報や本文を含まない外部呼び出しの監査）、api_rate_limits（分単位のカウンター）の 3 テーブルを追加します。外部アクセスは、サイト管理者が管理画面で有効化してトークンを発行するまでオフのままです。',
    'check' => static fn (): bool => db()->tableExists('api_tokens') && db()->tableExists('api_access_log') && db()->tableExists('api_rate_limits'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'api_tokens` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_id` int(11) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL DEFAULT \'\',
            `token_prefix` varchar(16) NOT NULL DEFAULT \'\',
            `token_hash` varchar(64) NOT NULL,
            `scopes` varchar(500) NOT NULL DEFAULT \'[]\',
            `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `expires_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `revoked_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `revoked_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `last_used_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `last_used_ip` varchar(45) NOT NULL DEFAULT \'\',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_api_tokens_hash` (`token_hash`),
            KEY `idx_api_tokens_admin` (`admin_id`, `revoked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'api_access_log` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `request_id` varchar(32) NOT NULL DEFAULT \'\',
            `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `token_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `client` varchar(80) NOT NULL DEFAULT \'\',
            `protocol` varchar(12) NOT NULL DEFAULT \'\',
            `method` varchar(40) NOT NULL DEFAULT \'\',
            `tool` varchar(64) NOT NULL DEFAULT \'\',
            `http_status` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `result` varchar(64) NOT NULL DEFAULT \'\',
            `target` varchar(100) NOT NULL DEFAULT \'\',
            `duration_ms` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `ip` varchar(45) NOT NULL DEFAULT \'\',
            PRIMARY KEY (`id`),
            KEY `idx_api_access_log_created` (`created_at`),
            KEY `idx_api_access_log_token` (`token_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'api_rate_limits` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `bucket` varchar(100) NOT NULL,
            `window_start` int(11) UNSIGNED NOT NULL,
            `hits` int(11) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_api_rate_limits_bucket` (`bucket`, `window_start`),
            KEY `idx_api_rate_limits_window` (`window_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
    // SQLite 建表时普通 KEY 会被丢掉，另建索引；MySQL 上同名索引已存在，_addIndex 幂等跳过。
    'php' => static function (): string {
        if (db()->isSqlite()) {
            _addIndex('api_tokens', 'idx_api_tokens_admin', '`admin_id`, `revoked_at`');
            _addIndex('api_access_log', 'idx_api_access_log_created', '`created_at`');
            _addIndex('api_access_log', 'idx_api_access_log_token', '`token_id`, `created_at`');
            _addIndex('api_rate_limits', 'idx_api_rate_limits_window', '`window_start`');
        }
        return 'ok';
    },
];
