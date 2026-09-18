<?php

declare(strict_types=1);

return [
    'id' => '20260915_blox_catalog_origin',
    'title' => 'Blox 模板来源绑定',
    'desc' => '记录安装及待确认模板的官方或社区来源；不推断旧记录来源。',
    'title_en' => 'Blox template origin binding',
    'desc_en' => 'Stores catalog origin for installations and reviews without guessing legacy origins.',
    'title_ja' => 'Blox テンプレートの提供元を固定',
    'desc_ja' => 'インストールと確認記録に提供元を保存します。既存記録の提供元は推測しません。',
    'check' => static fn (): bool => _columnExists('blox_remote_template_states', 'catalog_origin')
        && _columnExists('blox_import_reviews', 'catalog_origin'),
    'sqls' => [],
    'php' => static function (): string {
        foreach (['blox_remote_template_states', 'blox_import_reviews'] as $table) {
            if (!_columnExists($table, 'catalog_origin')) {
                db()->execute('ALTER TABLE ' . DB_PREFIX . $table
                    . " ADD COLUMN catalog_origin VARCHAR(16) NOT NULL DEFAULT ''");
            }
        }
        return 'Catalog origin columns ready.';
    },
];
