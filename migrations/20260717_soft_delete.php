<?php
/**
 * 回收站 / 软删除：contents 与 products 表加 deleted_at。
 * NULL = 正常；非 NULL = 已删除时间戳（进回收站）。见 includes/models/Model.php 软删除方法。
 */

declare(strict_types=1);

return [
    'id'    => '20260717_soft_delete',
    'title' => '回收站：contents / products 加 deleted_at',
    'desc'  => '为 yikai_contents / yikai_products 新增 deleted_at 列（int unsigned NULL，NULL=未删除）。删除内容/产品改为进回收站，可还原或彻底删除。',
    'check' => function (): bool {
        return _columnExists('contents', 'deleted_at') && _columnExists('products', 'deleted_at');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "contents` ADD COLUMN `deleted_at` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '软删除时间戳（NULL=未删除）'",
        "ALTER TABLE `" . DB_PREFIX . "contents` ADD INDEX `idx_contents_deleted` (`deleted_at`)",
        "ALTER TABLE `" . DB_PREFIX . "products` ADD COLUMN `deleted_at` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '软删除时间戳（NULL=未删除）'",
        "ALTER TABLE `" . DB_PREFIX . "products` ADD INDEX `idx_products_deleted` (`deleted_at`)",
    ],
];
