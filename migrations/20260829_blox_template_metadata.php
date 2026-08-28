<?php
/** Blox 模板场景推荐元数据。 */

declare(strict_types=1);

return [
    'id' => '20260829_blox_template_metadata',
    'title' => 'Blox 模板场景元数据',
    'desc' => '为模板库增加用途、适用页面和推荐优先级等目录元数据。',
    'title_en' => 'Blox template catalog metadata',
    'title_ja' => 'Blox テンプレートカタログメタデータ',
    'desc_en' => 'Adds catalog metadata for template purpose, page fit, and recommendation priority.',
    'desc_ja' => 'テンプレートの用途、対象ページ、推奨優先度を保存するメタデータを追加します。',
    'check' => static function (): bool {
        try {
            return _columnExists('blox_templates', 'metadata');
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "blox_templates` ADD COLUMN `metadata` longtext AFTER `requirements`",
    ],
];
