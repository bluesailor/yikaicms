<?php
/** Blox 头尾模板激活条件。 */

declare(strict_types=1);

return [
    'id' => '20260807_blox_template_conditions',
    'title' => 'Blox 模板激活条件',
    'desc' => 'blox_templates 新增 conditions 列：头尾模板按 全站/首页/栏目/单页 条件激活，特异性评分裁决。',
    // 站点语言非中文时用这几项；Migrator::label() 取不到会回落上面的中文原文
    'title_en' => 'Blox template conditions',
    'title_ja' => 'Blox テンプレート適用条件',
    'desc_en' => 'Adds condition fields so a Blox template can target specific pages.',
    'desc_ja' => 'Blox テンプレートを特定のページに適用するための条件フィールドを追加します。',
    'check' => static function (): bool {
        try {
            return _columnExists('blox_templates', 'conditions');
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "blox_templates` ADD COLUMN `conditions` longtext",
    ],
];
