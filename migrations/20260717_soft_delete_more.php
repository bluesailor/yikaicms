<?php
/**
 * 回收站扩展：albums / downloads / jobs 也支持软删除。
 * 与 20260717_soft_delete（contents/products）同机制，见 includes/models/Model.php。
 */

declare(strict_types=1);

return [
    'id'    => '20260717_soft_delete_more',
    'title' => '回收站扩展：albums / downloads / jobs 加 deleted_at',
    'desc'  => '为 yikai_albums / yikai_downloads / yikai_jobs 新增 deleted_at 列（int unsigned NULL）。相册、下载、招聘的删除也进回收站。',
    'check' => function (): bool {
        return _columnExists('albums', 'deleted_at')
            && _columnExists('downloads', 'deleted_at')
            && _columnExists('jobs', 'deleted_at');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "albums` ADD COLUMN `deleted_at` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '软删除时间戳（NULL=未删除）'",
        "ALTER TABLE `" . DB_PREFIX . "albums` ADD INDEX `idx_albums_deleted` (`deleted_at`)",
        "ALTER TABLE `" . DB_PREFIX . "downloads` ADD COLUMN `deleted_at` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '软删除时间戳（NULL=未删除）'",
        "ALTER TABLE `" . DB_PREFIX . "downloads` ADD INDEX `idx_downloads_deleted` (`deleted_at`)",
        "ALTER TABLE `" . DB_PREFIX . "jobs` ADD COLUMN `deleted_at` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '软删除时间戳（NULL=未删除）'",
        "ALTER TABLE `" . DB_PREFIX . "jobs` ADD INDEX `idx_jobs_deleted` (`deleted_at`)",
    ],
];
