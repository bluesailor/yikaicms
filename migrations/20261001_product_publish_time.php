<?php
/**
 * 产品定时上架（2.0.3）：yikai_products 加 publish_time。
 *
 * 文章早有「定时发布」（status=3 + publish_time，到点由 ScheduledPublish::sweep() 上线），
 * 产品只有上架 / 下架，没有时间字段可依。加一列后产品与文章走同一套规则。
 */

declare(strict_types=1);

return [
    'id' => '20261001_product_publish_time',
    'title' => '产品定时上架',
    'desc' => '为产品表新增上架时间字段（publish_time，int，默认 0）：选择「定时上架」的产品会在设定时间自动上架。已有产品不受影响。',
    'title_en' => 'Scheduled product publishing',
    'desc_en' => 'Adds a publish time column (publish_time, int, default 0) to the products table so products set to "scheduled" go on sale automatically at that time. Existing products are not affected.',
    'title_ja' => '商品の予約公開',
    'desc_ja' => '商品テーブルに公開日時の列（publish_time、int、初期値 0）を追加します。「予約公開」にした商品は設定日時に自動で公開されます。既存の商品には影響しません。',
    'check' => static fn (): bool => _columnExists('products', 'publish_time'),
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "products` ADD COLUMN `publish_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '上架时间（定时上架用）' AFTER `status`",
    ],
];
