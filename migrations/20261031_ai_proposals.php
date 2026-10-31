<?php
/** v2.2 AI 文章草稿提案（ADR-002）：提案集、提案项，以及 AI 变更日志的提案关联列。只建表/加列，不产生任何提案。 */

declare(strict_types=1);

return [
    'id'    => '20261031_ai_proposals',
    'title' => 'AI 文章草稿提案',
    'desc'  => '新增 ai_proposals、ai_proposal_items 两张表，保存外部 AI 客户端提交、待编辑审核的文章草稿提案；AI 变更日志增加提案关联与撤销校验字段。提案在后台审核应用前不会写入任何文章。',
    'title_en' => 'AI article draft proposals',
    'title_ja' => 'AI 記事下書き提案',
    'desc_en' => 'Adds the ai_proposals and ai_proposal_items tables, which hold article draft proposals sent by external AI clients for editor review, and adds proposal and undo-check columns to the AI change log. No article is written until a proposal is applied in the admin.',
    'desc_ja' => '外部 AI クライアントが送信し、編集者のレビューを待つ記事下書き提案を保存する ai_proposals と ai_proposal_items の 2 テーブルを追加し、AI 変更ログに提案との関連付けと取り消し確認用の列を追加します。管理画面で提案を適用するまで、記事には何も書き込まれません。',
    'check' => static fn (): bool => db()->tableExists('ai_proposals') && db()->tableExists('ai_proposal_items')
        && db()->tableExists('ai_change_log') && _columnExists('ai_change_log', 'after_revision'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'ai_proposals` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_id` varchar(32) NOT NULL,
            `owner_id` int(11) UNSIGNED NOT NULL,
            `source_ns` varchar(40) NOT NULL,
            `token_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `tool` varchar(64) NOT NULL,
            `idempotency_key` varchar(128) NOT NULL,
            `payload_hash` varchar(64) NOT NULL,
            `contract_version` varchar(20) NOT NULL,
            `created_at` int(11) UNSIGNED NOT NULL,
            `expires_at` int(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_ai_proposals_public` (`public_id`),
            UNIQUE KEY `uk_ai_proposals_idempotency` (`owner_id`, `source_ns`, `tool`, `idempotency_key`),
            KEY `idx_ai_proposals_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'ai_proposal_items` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_id` varchar(32) NOT NULL,
            `proposal_id` int(11) UNSIGNED NOT NULL,
            `seq` int(11) UNSIGNED NOT NULL,
            `owner_id` int(11) UNSIGNED NOT NULL,
            `operation` varchar(10) NOT NULL,
            `target_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `lang` varchar(10) NOT NULL,
            `channel_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `input_json` mediumtext NOT NULL,
            `input_hash` varchar(64) NOT NULL,
            `base_revision` varchar(64) NOT NULL,
            `contract_version` varchar(20) NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT \'pending\',
            `status_reason` varchar(64) NOT NULL DEFAULT \'\',
            `result_content_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `result_revision` varchar(64) NOT NULL DEFAULT \'\',
            `change_log_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `decided_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `decided_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` int(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_ai_proposal_items_public` (`public_id`),
            KEY `idx_ai_proposal_items_proposal` (`proposal_id`, `seq`),
            KEY `idx_ai_proposal_items_owner` (`owner_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        // 后台 AI 助手从前按需建这张表；没用过助手的站点在这里一次建好完整结构（事务里不能再建表）。
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'ai_change_log` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `admin_name` varchar(100) NOT NULL DEFAULT \'\',
            `ability` varchar(100) NOT NULL,
            `target` varchar(200) NOT NULL DEFAULT \'\',
            `summary` varchar(500) NOT NULL DEFAULT \'\',
            `before_json` mediumtext NULL,
            `input_json` mediumtext NULL,
            `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `undone` tinyint(1) NOT NULL DEFAULT 0,
            `undone_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_admin` (`admin_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
    // 已用过 AI 助手的站点表已存在：逐列补齐（幂等）；SQLite 的普通索引另建。
    'php' => static function (): string {
        _addColumn('ai_change_log', 'proposal_item_id', "int(11) UNSIGNED NOT NULL DEFAULT 0");
        _addColumn('ai_change_log', 'source_ns', "varchar(40) NOT NULL DEFAULT ''");
        _addColumn('ai_change_log', 'target_type', "varchar(20) NOT NULL DEFAULT ''");
        _addColumn('ai_change_log', 'target_id', "int(11) UNSIGNED NOT NULL DEFAULT 0");
        _addColumn('ai_change_log', 'operation', "varchar(10) NOT NULL DEFAULT ''");
        _addColumn('ai_change_log', 'after_revision', "varchar(64) NOT NULL DEFAULT ''");
        _addColumn('ai_change_log', 'undone_by', "int(11) UNSIGNED NOT NULL DEFAULT 0");
        _addIndex('ai_change_log', 'idx_ai_change_log_item', '`proposal_item_id`');
        if (db()->isSqlite()) {
            _addIndex('ai_change_log', 'idx_admin', '`admin_id`');
            _addIndex('ai_change_log', 'idx_created', '`created_at`');
            _addIndex('ai_proposals', 'idx_ai_proposals_expires', '`expires_at`');
            _addIndex('ai_proposal_items', 'idx_ai_proposal_items_proposal', '`proposal_id`, `seq`');
            _addIndex('ai_proposal_items', 'idx_ai_proposal_items_owner', '`owner_id`, `status`');
        }
        return 'ok';
    },
];
