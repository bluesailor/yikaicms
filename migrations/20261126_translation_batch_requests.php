<?php
/**
 * 批量翻译按实际模型请求计额度（2.3.1）：加请求计数、旧批次标记、暂停时保存已成功回复的列。只加列，不改内容。
 * 升级前已有的批次只知道「生成次数」（calls），请求数补不出来：requests_known = 0，后台显示「未知」。
 * 升级前的上限（max_calls）按「生成次数」算（默认每项 3 次），升级后按模型请求算：一次生成要发好几次请求，
 * 还没跑完的批次照旧上限会提前暂停。所以进行中、手动暂停的旧批次按剩余项重新给额度（每项 16 次，
 * 与 TranslationBatches::REQUESTS_PER_ITEM 相同）。因额度暂停的旧批次保留原值（继续时 resume() 按同样的算法重新给额度，
 * 批次页说明旧上限按生成次数算）；已完成、已取消的批次保留原值，只作记录。不限次数（0）的不变。
 */

declare(strict_types=1);

return [
    'id'    => '20261126_translation_batch_requests',
    'title' => '批量翻译按模型请求计额度',
    'desc'  => '给 translation_batches 加 requests（实际发出的模型请求数）与 requests_known（升级前的批次记为 0，请求数显示为「未知」），给 translation_batch_items 加 partial_json（保存已成功的模型回复，暂停、出错或中断后继续时不再重发）。进行中、手动暂停的旧批次按剩余项重新给请求额度（每项 16 次），因为旧上限按生成次数算。不改动译文与其它内容。',
    'title_en' => 'Count batch translation by model requests',
    'title_ja' => '一括翻訳をモデルへのリクエスト数で集計',
    'desc_en' => 'Adds requests (model requests actually sent) and requests_known (0 for batches created before this upgrade, shown as "Unknown") to translation_batches, and partial_json to translation_batch_items (model replies already received when a batch pauses at its limit, reused on resume instead of being sent again). Running and manually paused batches get a new request limit for their remaining items (16 each), since the old limit counted generation attempts. Translations and other content are not changed.',
    'desc_ja' => 'translation_batches に requests（実際に送信したモデルへのリクエスト数）と requests_known（このアップグレード以前のバッチは 0 で「不明」と表示）を、translation_batch_items に partial_json（上限で一時停止したときに受信済みのモデル応答を保存し、再開時に再送しない）を追加します。実行中および手動で一時停止したバッチには残りの項目数に応じて新しいリクエスト上限（1 件あたり 16 回）を設定します（従来の上限は生成回数で数えていたため）。翻訳やその他のコンテンツは変更しません。',
    'check' => static fn (): bool => _columnExists('translation_batches', 'requests') && _columnExists('translation_batches', 'requests_known')
        && _columnExists('translation_batch_items', 'partial_json'),
    'sqls' => [],
    'php' => static function (): string {
        _addColumn('translation_batches', 'requests', 'int(11) UNSIGNED NOT NULL DEFAULT 0');
        // 只在这一列是刚加上时把已有批次标成「请求数未知」；重复执行不改新批次
        if (_addColumn('translation_batches', 'requests_known', 'tinyint(1) UNSIGNED NOT NULL DEFAULT 1')) {
            db()->execute('UPDATE ' . DB_PREFIX . 'translation_batches SET requests_known = 0');
            $open = '(SELECT COUNT(*) FROM ' . DB_PREFIX . 'translation_batch_items i WHERE i.batch_id = ' . DB_PREFIX . "translation_batches.id AND i.status IN ('pending', 'generating', 'generated'))";
            db()->execute('UPDATE ' . DB_PREFIX . "translation_batches SET max_calls = 16 * {$open} WHERE (status = 'running' OR (status = 'paused' AND note <> 'budget')) AND max_calls > 0 AND {$open} > 0");
        }
        _addColumn('translation_batch_items', 'partial_json', 'mediumtext NULL');
        return 'ok';
    },
];
