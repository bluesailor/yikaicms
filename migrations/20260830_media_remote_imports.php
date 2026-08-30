<?php
/** 官方远程素材导入来源表。 */

declare(strict_types=1);

return [
    'id' => '20260830_media_remote_imports',
    'title' => '官方远程素材导入记录',
    'desc' => '记录 Yikai 官方素材导入后的本地媒体、素材 ID、版本、哈希和授权快照，用于去重与审计。',
    'title_en' => 'Official remote media import records',
    'title_ja' => '公式リモート素材インポート記録',
    'desc_en' => 'Tracks imported official media by local media, asset id, version, hash and license snapshot for deduplication and audit.',
    'desc_ja' => 'インポート済み公式素材のローカルメディア、素材ID、バージョン、ハッシュ、ライセンス情報を記録します。',
    'check' => static function (): bool {
        try {
            return db()->tableExists('media_remote_imports');
        } catch (Throwable) {
            return false;
        }
    },
    'sqls' => [
        "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "media_remote_imports` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `media_id` int(11) unsigned NOT NULL,
            `provider` varchar(100) NOT NULL DEFAULT 'update.yikaicms.com',
            `asset_id` varchar(100) NOT NULL,
            `asset_version` varchar(50) NOT NULL DEFAULT '',
            `sha256` char(64) NOT NULL DEFAULT '',
            `license_code` varchar(100) NOT NULL DEFAULT '',
            `attribution` varchar(255) NOT NULL DEFAULT '',
            `imported_by` int(11) unsigned NOT NULL DEFAULT 0,
            `imported_at` int(11) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_provider_asset_version` (`provider`, `asset_id`, `asset_version`),
            KEY `idx_media_id` (`media_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ],
];
