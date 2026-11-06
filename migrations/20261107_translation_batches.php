<?php
/** 批量翻译任务（评审 T03）：批次与逐项状态。只建表，不产生任何任务、不改任何内容。 */

declare(strict_types=1);

return [
    'id'    => '20261107_translation_batches',
    'title' => '批量翻译任务',
    'desc'  => '新增 translation_batches、translation_batch_items 两张表，保存后台批量 AI 翻译的进度与生成结果：页面关闭或请求中断后可以接着跑，已完成的项不会重复调用模型或重复写入。只建表，不改动已有内容。',
    'title_en' => 'Batch translation tasks',
    'title_ja' => '一括翻訳タスク',
    'desc_en' => 'Adds the translation_batches and translation_batch_items tables, which keep the progress and generated output of batch AI translation in the admin, so a batch can resume after the page is closed or a request is interrupted without calling the model or writing twice. Creates tables only; existing content is not changed.',
    'desc_ja' => '管理画面の一括 AI 翻訳の進行状況と生成結果を保存する translation_batches と translation_batch_items の 2 テーブルを追加します。ページを閉じたりリクエストが中断したりしても続きから実行でき、完了済みの項目でモデルの再呼び出しや二重書き込みは起きません。テーブルを追加するだけで、既存のコンテンツは変更しません。',
    'check' => static fn (): bool => db()->tableExists('translation_batches') && db()->tableExists('translation_batch_items'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'translation_batches` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `content_type` varchar(20) NOT NULL,
            `langs` varchar(255) NOT NULL DEFAULT \'\',
            `status` varchar(16) NOT NULL DEFAULT \'running\',
            `total` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` int(11) UNSIGNED NOT NULL,
            `updated_at` int(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_translation_batches_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'translation_batch_items` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) UNSIGNED NOT NULL,
            `source_id` int(11) UNSIGNED NOT NULL,
            `lang` varchar(10) NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT \'pending\',
            `attempts` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `target_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `source_fp` varchar(64) NOT NULL DEFAULT \'\',
            `result_json` mediumtext NULL,
            `error` varchar(255) NOT NULL DEFAULT \'\',
            `lease_until` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` int(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_translation_batch_items` (`batch_id`, `source_id`, `lang`),
            KEY `idx_translation_batch_items_status` (`batch_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
    // SQLite 建表转换保留唯一约束、丢掉普通索引：普通索引另建
    'php' => static function (): string {
        if (db()->isSqlite()) {
            _addIndex('translation_batches', 'idx_translation_batches_created', '`created_at`');
            _addIndex('translation_batch_items', 'idx_translation_batch_items_status', '`batch_id`, `status`');
        }
        return 'ok';
    },
];
