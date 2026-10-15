<?php
/**
 * 统一密钥存储（v2.1，路线图 §5.4，借鉴 WordPress 7.2 Secrets API）。
 *
 * AI 密钥、SMTP 密码、百度推送令牌等存进 settings 时统一封装成 `yks1:` 信封：
 * AES-256-GCM、每次随机 nonce、带认证标签，密钥由 config.php 的 ENCRYPT_KEY 派生。
 *
 * - 读：信封 → 解密；旧格式（明文、AI 密钥的旧 AES-128-CBC 固定 IV）照常读出，升级前后都不断；
 * - 写：put() 永远写信封；空串 = 清空；
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
     * 加密为什么用不了（2.1.1：站点健康与设置页据此提示，不再静默存明文）：
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
     * 明文 → 信封。环境不支持（没有 ENCRYPT_KEY / openssl）或加密失败时原样返回，不让保存失败——
     * 所以「密钥一定加密保存」不成立：站点健康的 secret_store 会列出存成明文的核心密钥（2.1.1），
     * 环境明明支持却加密失败的写一条错误日志（不带密钥内容）。
     */
    public static function seal(string $plaintext): string
    {
        if ($plaintext === '' || self::isSealed($plaintext) || !self::available()) {
            return $plaintext;
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if ($cipher === false) {
            error_log('[SecretStore] ' . self::CIPHER . ' encryption failed; the secret was stored as plain text');
            return $plaintext;
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

    /** 写一个设置里的密钥；空串 = 清空。 */
    public static function put(string $name, string $plaintext): void
    {
        settingModel()->set($name, $plaintext === '' ? '' : self::seal($plaintext));
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
            db()->update('settings', ['value' => self::seal($plain)], '`key` = ?', [$name]);
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
