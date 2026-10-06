<?php
/** v2.1 统一密钥存储：AI 密钥（旧 AES-128-CBC 固定 IV）与 SMTP 密码（明文）改存 AES-256-GCM 信封。 */

declare(strict_types=1);

return [
    'id' => '20261007_secret_store',
    'title' => '密钥加密保存',
    'desc' => 'AI 密钥与 SMTP 密码改为 AES-256-GCM 加密保存（密钥由 config.php 的 ENCRYPT_KEY 派生）。旧值照常可读；降级到旧版前请用升级前的数据库备份还原。',
    'title_en' => 'Encrypted secret storage',
    'title_ja' => 'シークレットの暗号化保存',
    'desc_en' => 'Stores the AI key and SMTP password with AES-256-GCM (key derived from ENCRYPT_KEY in config.php). Old values still read; restore the pre-upgrade database backup before downgrading.',
    'desc_ja' => 'AI キーと SMTP パスワードを AES-256-GCM で暗号化して保存します（鍵は config.php の ENCRYPT_KEY から派生）。旧形式の値もそのまま読めます。ダウングレード前には更新前のデータベースバックアップを復元してください。',
    'check' => static function (): bool {
        if (!class_exists('SecretStore') || !SecretStore::available()) {
            return true; // 没有 ENCRYPT_KEY 的环境：无从加密，不阻塞升级
        }
        foreach (SecretStore::CORE_KEYS as $name) {
            $row = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$name]);
            $value = is_array($row) ? (string) ($row['value'] ?? '') : '';
            if ($value !== '' && !SecretStore::isSealed($value)) {
                return false;
            }
        }
        return true;
    },
    'php' => static function (): string {
        return 'sealed ' . SecretStore::sealLegacyCoreSecrets();
    },
];
