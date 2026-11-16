<?php
/** 批量翻译后台执行（2.3.0 CORE-01）：每批的调用上限与计数、任务日志。只加列与新表，不改任何内容。 */

declare(strict_types=1);

return [
    'id'    => '20261116_translation_batch_worker',
    'title' => '批量翻译后台执行',
    'desc'  => '给 translation_batches 加 calls、max_calls、note 三列（每批的模型调用次数与上限、暂停原因），新增 translation_batch_events 表记录任务日志。只加列与建表，不改动已有内容。',
    'title_en' => 'Background batch translation',
    'title_ja' => '一括翻訳のバックグラウンド実行',
    'desc_en' => 'Adds the calls, max_calls and note columns to translation_batches (model calls used per batch, their limit and the pause reason) and a translation_batch_events table for the task log. Adds columns and a table only; existing content is not changed.',
    'desc_ja' => 'translation_batches に calls、max_calls、note の 3 列（バッチごとのモデル呼び出し回数と上限、一時停止の理由）を追加し、タスクログ用の translation_batch_events テーブルを追加します。列とテーブルの追加のみで、既存のコンテンツは変更しません。',
    'check' => static fn (): bool => db()->tableExists('translation_batch_events')
        && _columnExists('translation_batches', 'calls') && _columnExists('translation_batches', 'max_calls') && _columnExists('translation_batches', 'note'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'translation_batch_events` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) UNSIGNED NOT NULL,
            `item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `event` varchar(20) NOT NULL,
            `detail` varchar(255) NOT NULL DEFAULT \'\',
            `created_at` int(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_translation_batch_events_batch` (`batch_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
    'php' => static function (): string {
        _addColumn('translation_batches', 'calls', 'int(11) UNSIGNED NOT NULL DEFAULT 0');
        _addColumn('translation_batches', 'max_calls', 'int(11) UNSIGNED NOT NULL DEFAULT 0');
        _addColumn('translation_batches', 'note', "varchar(32) NOT NULL DEFAULT ''");
        // SQLite 建表转换丢掉普通索引：另建
        if (db()->isSqlite()) _addIndex('translation_batch_events', 'idx_translation_batch_events_batch', '`batch_id`, `id`');
        return 'ok';
    },
];
