<?php
/** Published Blox documents for data-backed channel landing pages. */

declare(strict_types=1);

return [
    'id' => '20260818_blox_channel_published_documents',
    'title' => 'Blox 栏目页发布存储',
    'desc' => '为新闻等数据栏目增加独立的已发布版式字段，避免与文章内容记录混用。',
    'title_en' => 'Blox channel publication storage',
    'title_ja' => 'Blox チャンネル公開ストレージ',
    'desc_en' => 'Adds dedicated published layout storage for data-backed channels such as News.',
    'desc_ja' => 'ニュースなどのデータ型チャンネル向けに、公開レイアウト専用ストレージを追加します。',
    'check' => static fn (): bool => _columnExists('blox_page_drafts', 'published_data'),
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "blox_page_drafts` ADD COLUMN `published_data` longtext NULL AFTER `draft_data`",
    ],
];
