<?php
/** 导入检查-确认两段式的服务端待确认记录表。 */

declare(strict_types=1);

return [
    'id' => '20260914_add_blox_import_reviews',
    'title' => '新增 Blox 导入待确认记录表',
    'desc' => '远程模板安装/另存副本/更新与画布插入改为检查-确认两段式：检查阶段把已验证的原始包连同设计版本登记为服务端待确认记录，确认动作只凭记录执行，浏览器不能自带包或哈希通过校验。记录带 TTL 与容量上限，仅存私有包内容，不进公开 uploads。',
    'title_en' => 'Add pending Blox import review table',
    'desc_en' => 'Remote template install/copy/update and canvas insertion now use a check-then-confirm flow: the check stores the verified package plus the design revision server-side, and confirmation only consumes that record, so a browser cannot smuggle its own package or hash. Records are capped with a TTL and never written to public uploads.',
    'title_ja' => 'Blox インポート確認待ちテーブルを追加',
    'desc_ja' => 'リモートテンプレートのインストール・コピー・更新とキャンバス挿入を確認 2 段階にします。検査段階で検証済みパッケージとデザイン版をサーバー側に記録し、確認操作はその記録のみを受け付けます。ブラウザが独自のパッケージやハッシュを持ち込むことはできません。レコードは TTL と件数上限付きで、公開 uploads には保存しません。',
    'check' => static fn (): bool => db()->tableExists('blox_import_reviews'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS ' . DB_PREFIX . 'blox_import_reviews (
            id VARCHAR(64) NOT NULL,
            admin_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
            operation VARCHAR(32) NOT NULL,
            source_key VARCHAR(128) NOT NULL,
            source_type VARCHAR(32) NOT NULL DEFAULT \'\',
            template_type VARCHAR(64) NOT NULL DEFAULT \'\',
            package_sha256 CHAR(64) NOT NULL,
            package_json MEDIUMTEXT NOT NULL,
            package_version VARCHAR(50) NOT NULL DEFAULT \'\',
            target_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
            target_revision VARCHAR(64) NOT NULL DEFAULT \'\',
            result_ref VARCHAR(128) NOT NULL DEFAULT \'\',
            design_revision INT(11) UNSIGNED NOT NULL DEFAULT 0,
            created_at INT(11) UNSIGNED NOT NULL DEFAULT 0,
            expires_at INT(11) UNSIGNED NOT NULL DEFAULT 0,
            consumed_at INT(11) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY `idx_admin_pending` (`admin_id`, `consumed_at`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    ],
];
