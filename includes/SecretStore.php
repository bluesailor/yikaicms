<?php
/**
 * 统一密钥存储（v2.1，路线图 §5.4，借鉴 WordPress 7.2 Secrets API）。
 *
 * AI 密钥、SMTP 密码、百度推送令牌等存进 settings 时统一封装成 `yks1:` 信封：
 * AES-256-GCM、每次随机 nonce、带认证标签，密钥由 config.php 的 ENCRYPT_KEY 派生。
 *
 * - 读：信封 → 解密；旧格式（明文、AI 密钥的旧 AES-128-CBC 固定 IV）照常读出，升级前后都不断；
 * - 写：put() 只写信封；空串 = 清空。加密不可用或失败时抛 SecretStoreException、不写库，原值保持不变——
 *   v2.2 起不再降级存明文（2.1.1–2.1.3 只提示）。旧明文 / 旧 CBC 值照常可读，也不会被自动改写；
 * - 界面只显示末四位（masked()），留空即不改由各设置页处理；
 * - 永不随整站模板 / 配方导出（SensitiveSettings 按键名排除），不进日志。
 *
 * 降级到 2.0.5 时旧代码读不懂信封：升级前的数据库备份可还原，升级说明写明。
 */

declare(strict_types=1);

final class SecretStore
{
    public const PREFIX = 'yks1:';
    /** 核心持有的密钥：迁移时统一封装。插件自己的密钥（如 seo_baidu_token）由插件写入时封装。 */
    public const CORE_KEYS = ['ai_api_key', 'smtp_pass'];
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function available(): bool
    {
        return self::unavailableReason() === '';
    }

    /**
     * 加密为什么用不了（站点健康与设置页据此提示；不可用时新密钥一律存不进去）：
     * 'key' = config.php 没有有效的 ENCRYPT_KEY；'cipher' = 服务器 OpenSSL 不支持 AES-256-GCM；能用返回 ''。
     */
    public static function unavailableReason(): string
    {
        if (!defined('ENCRYPT_KEY') || !is_string(ENCRYPT_KEY) || ENCRYPT_KEY === '' || ENCRYPT_KEY === '{{ENCRYPT_KEY}}') {
            return 'key';
        }
        if (!function_exists('openssl_encrypt') || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            return 'cipher';
        }
        return '';
    }

    /**
     * 存着值却还不是信封的核心密钥（2.1.1 站点健康）：加密不可用时保存的，或升级时环境还不支持。
     *
     * @return list<string> 键名
     */
    public static function plaintextCoreSecrets(): array
    {
        $names = [];
        foreach (self::CORE_KEYS as $name) {
            $value = db()->fetchColumn('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$name]);
            if (is_string($value) && $value !== '' && !self::isSealed($value)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * 明文 → 信封。环境不支持（没有 ENCRYPT_KEY / openssl）或加密失败时抛 SecretStoreException，
     * 绝不原样返回明文：调用方据此拒绝保存。异常与日志只带原因，不带密钥内容。
     *
     * @throws SecretStoreException
     */
    public static function seal(string $plaintext): string
    {
        if ($plaintext === '' || self::isSealed($plaintext)) {
            return $plaintext;
        }
        $reason = self::unavailableReason();
        if ($reason !== '') {
            throw new SecretStoreException($reason);
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if ($cipher === false) {
            error_log('[SecretStore] ' . self::CIPHER . ' encryption failed; the secret was not saved');
            throw new SecretStoreException(SecretStoreException::FAILED);
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /**
     * 存储值 → 明文。信封解不开（ENCRYPT_KEY 换了、数据被改）返回空串——宁可当作未配置，也不把密文当密码用。
     * 非信封按旧格式处理：$legacyCbc 为真时先试旧的 AI 密钥格式，失败则视为明文。
     */
    public static function open(string $stored, bool $legacyCbc = false): string
    {
        if ($stored === '') {
            return '';
        }
        if (!self::isSealed($stored)) {
            return $legacyCbc ? self::openLegacyCbc($stored) : $stored;
        }
        if (!self::available()) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::NONCE_BYTES + self::TAG_BYTES) {
            return '';
        }
        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $plain = openssl_decrypt(substr($raw, self::NONCE_BYTES + self::TAG_BYTES), self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return is_string($plain) ? $plain : '';
    }

    /** 读一个设置里的密钥（config() 取原始值后解开）。 */
    public static function get(string $name): string
    {
        $stored = function_exists('config') ? config($name, '') : '';
        return self::open(is_scalar($stored) ? (string) $stored : '', $name === 'ai_api_key');
    }

    /**
     * 写一个设置里的密钥；空串 = 清空（清空不需要加密，环境不支持时也能做）。
     * 先加密再写库：加密失败抛异常时数据库里还是原来的值。
     *
     * @throws SecretStoreException
     */
    public static function put(string $name, string $plaintext): void
    {
        $stored = $plaintext === '' ? '' : self::seal($plaintext);
        settingModel()->set($name, $stored);
    }

    /** 界面展示：只露末四位；未设置返回空串。 */
    public static function masked(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        return '****' . (mb_strlen($plaintext) > 8 ? mb_substr($plaintext, -4) : '');
    }

    /**
     * 迁移：核心密钥的旧值（明文 / 旧 CBC）封装成信封。已是信封的跳过。返回处理个数。
     *
     * @psalm-suppress PossiblyUnusedMethod 消费方为 migrations/20261007_secret_store.php（迁移文件不在 Psalm 扫描集）
     */
    public static function sealLegacyCoreSecrets(): int
    {
        if (!self::available()) {
            return 0;
        }
        $count = 0;
        foreach (self::CORE_KEYS as $name) {
            $row = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$name]);
            $stored = is_array($row) ? (string) ($row['value'] ?? '') : '';
            if ($stored === '' || self::isSealed($stored)) {
                continue;
            }
            $plain = self::open($stored, $name === 'ai_api_key');
            if ($plain === '') {
                continue;
            }
            try {
                $sealed = self::seal($plain);
            } catch (SecretStoreException) {
                continue;   // 加密失败：保留原值，站点健康会继续列出它
            }
            db()->update('settings', ['value' => $sealed], '`key` = ?', [$name]);
            $count++;
        }
        if ($count > 0 && function_exists('cacheClear')) {
            cacheClear();
        }
        return $count;
    }

    private static function key(): string
    {
        // available() 已确认常量存在；用 constant() 读，CI 没有 config.php 时 Psalm 也不报未定义
        return hash('sha256', 'yikai-secret-store|' . (string) constant('ENCRYPT_KEY'), true);
    }

    /** 2.0.x 及以前的 AI 密钥格式：AES-128-CBC，IV 取 md5(ENCRYPT_KEY) 前 16 位（固定 IV，只读兼容）。 */
    private static function openLegacyCbc(string $stored): string
    {
        $key = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : 'yikaicms_default_key';
        $decrypted = openssl_decrypt($stored, 'AES-128-CBC', $key, 0, substr(md5($key), 0, 16));
        return is_string($decrypted) && $decrypted !== '' ? $decrypted : $stored;
    }
}

/**
 * 密钥存不进去：消息只是原因代码（'key' / 'cipher' / 'failed'），不含密钥内容，可以直接进日志。
 * 界面用 userMessageKey() 取语言键。
 */
final class SecretStoreException extends RuntimeException
{
    public const FAILED = 'failed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('secret_store_' . $reason);
    }

    public function userMessageKey(): string
    {
        return match ($this->reason) {
            'key' => 'secret_store_save_blocked_key',
            'cipher' => 'secret_store_save_blocked_cipher',
            default => 'secret_store_save_failed',
        };
    }
}
