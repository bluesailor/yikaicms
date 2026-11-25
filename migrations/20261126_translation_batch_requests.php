<?php
/**
 * 批量翻译按实际模型请求计额度（2.3.1）：加请求计数、旧批次标记、暂停时保存已成功回复的列。只加列，不改内容。
 * 升级前已有的批次只知道「生成次数」（calls），请求数补不出来：requests_known = 0，后台显示「未知」。
 */

declare(strict_types=1);

return [
    'id'    => '20261126_translation_batch_requests',
    'title' => '批量翻译按模型请求计额度',
    'desc'  => '给 translation_batches 加 requests（实际发出的模型请求数）与 requests_known（升级前的批次记为 0，请求数显示为「未知」），给 translation_batch_items 加 partial_json（因额度暂停时保存已成功的模型回复，继续时不再重发）。只加列，不改动已有内容。',
    'title_en' => 'Count batch translation by model requests',
    'title_ja' => '一括翻訳をモデルへのリクエスト数で集計',
    'desc_en' => 'Adds requests (model requests actually sent) and requests_known (0 for batches created before this upgrade, shown as "Unknown") to translation_batches, and partial_json to translation_batch_items (model replies already received when a batch pauses at its limit, reused on resume instead of being sent again). Adds columns only; existing content is not changed.',
    'desc_ja' => 'translation_batches に requests（実際に送信したモデルへのリクエスト数）と requests_known（このアップグレード以前のバッチは 0 で「不明」と表示）を、translation_batch_items に partial_json（上限で一時停止したときに受信済みのモデル応答を保存し、再開時に再送しない）を追加します。列の追加のみで、既存のコンテンツは変更しません。',
    'check' => static fn (): bool => _columnExists('translation_batches', 'requests') && _columnExists('translation_batches', 'requests_known')
        && _columnExists('translation_batch_items', 'partial_json'),
    'sqls' => [],
    'php' => static function (): string {
        _addColumn('translation_batches', 'requests', 'int(11) UNSIGNED NOT NULL DEFAULT 0');
        // 只在这一列是刚加上时把已有批次标成「请求数未知」；重复执行不改新批次
        if (_addColumn('translation_batches', 'requests_known', 'tinyint(1) UNSIGNED NOT NULL DEFAULT 1')) {
            db()->execute('UPDATE ' . DB_PREFIX . 'translation_batches SET requests_known = 0');
        }
        _addColumn('translation_batch_items', 'partial_json', 'mediumtext NULL');
        return 'ok';
    },
];
