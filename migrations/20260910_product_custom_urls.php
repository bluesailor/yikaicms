<?php
declare(strict_types=1);

return [
    'id' => '20260910_product_custom_urls',
    'title' => '产品与分类自定义网址',
    'title_en' => 'Custom product and category URLs',
    'title_ja' => '商品とカテゴリのカスタム URL',
    'desc' => '增加统一路径注册表，保留现有产品和分类的网址规则，不自动修改任何地址。',
    'desc_en' => 'Adds a shared path registry without changing existing product or category URLs.',
    'desc_ja' => '共通パス登録表を追加します。既存の商品・カテゴリの URL は変更しません。',
    'check' => static fn(): bool => db()->tableExists('product_routes'),
    'sqls' => [],
    'php' => static function (): string {
        $table = DB_PREFIX . 'product_routes';
        if (db()->isSqlite()) {
            db()->execute('CREATE TABLE IF NOT EXISTS ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, path TEXT NOT NULL, path_key TEXT NOT NULL,
                UNIQUE (entity_type, entity_id), UNIQUE (path_key))');
        } else {
            db()->execute('CREATE TABLE IF NOT EXISTS ' . $table . ' (id int unsigned NOT NULL AUTO_INCREMENT,
                entity_type varchar(16) NOT NULL, entity_id int unsigned NOT NULL, path varchar(1500) NOT NULL,
                path_key varchar(64) NOT NULL, PRIMARY KEY (id), UNIQUE KEY entity_route (entity_type, entity_id),
                UNIQUE KEY route_path (path_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        }
        return 'Product URL registry ready';
    },
];
