<?php
/** 译文来源与审阅状态（评审 T04）。只建表；已有译文不回填，按「未追踪」处理、受保护。 */

declare(strict_types=1);

return [
    'id'    => '20261108_translation_links',
    'title' => '译文来源追踪',
    'desc'  => '新增 translation_links 表，记录每条 AI 译文由哪个版本的原文生成、是否有人改过或审过：原文改了会标出过期，人工改过或审过的译文不会被重新翻译直接覆盖，而是先给出逐字段对比由人决定。只建表；已有译文不回填，显示为「未追踪」并按受保护处理。',
    'title_en' => 'Translation source tracking',
    'title_ja' => '翻訳元の追跡',
    'desc_en' => 'Adds the translation_links table, which records which version of the original each AI translation was generated from and whether someone has edited or reviewed it. Translations are marked outdated when the original changes, and edited or reviewed translations are never overwritten by retranslation; the new text is offered for field-by-field review instead. Creates a table only; existing translations are not backfilled and show as untracked, which keeps them protected.',
    'desc_ja' => '各 AI 翻訳がどの版の原文から生成されたか、また人が編集・確認したかを記録する translation_links テーブルを追加します。原文が変わると翻訳は「古い」と表示され、編集済み・確認済みの翻訳は再翻訳で上書きされず、新しい訳文を項目ごとに比較して人が採用を決めます。テーブルを追加するだけで、既存の翻訳はさかのぼって登録せず「未追跡」として保護されます。',
    'check' => static fn (): bool => db()->tableExists('translation_links'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'translation_links` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `table_name` varchar(40) NOT NULL,
            `target_id` int(11) UNSIGNED NOT NULL,
            `source_id` int(11) UNSIGNED NOT NULL,
            `lang` varchar(10) NOT NULL,
            `source_fp` varchar(64) NOT NULL DEFAULT \'\',
            `target_fp` varchar(64) NOT NULL DEFAULT \'\',
            `state` varchar(16) NOT NULL DEFAULT \'machine\',
            `reviewed_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `reviewed_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `proposal_json` mediumtext NULL,
            `proposal_source_fp` varchar(64) NOT NULL DEFAULT \'\',
            `proposal_target_fp` varchar(64) NOT NULL DEFAULT \'\',
            `proposal_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` int(11) UNSIGNED NOT NULL,
            `updated_at` int(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_translation_links_target` (`table_name`, `target_id`),
            KEY `idx_translation_links_source` (`table_name`, `source_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
    // SQLite 建表转换保留唯一约束、丢掉普通索引：普通索引另建
    'php' => static function (): string {
        if (db()->isSqlite()) _addIndex('translation_links', 'idx_translation_links_source', '`table_name`, `source_id`');
        return 'ok';
    },
];
