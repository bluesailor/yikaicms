<?php
/**
 * v2.1 统一密钥存储：信封往返、篡改与换钥匙读不出、旧格式只读兼容、末四位掩码、迁移封装核心密钥。
 * v2.2：加密不可用时拒绝写入（不再降级存明文），原值不变，异常与日志不含密钥。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiProfiles;
use AiService;
use SecretStore;
use SecretStoreException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/SecretStore.php';
require_once ROOT_PATH . '/includes/AiService.php';
require_once ROOT_PATH . '/includes/AiProfiles.php';

final class SecretStoreTest extends TestCase
{
    protected function setUp(): void
    {
        // 「没有 ENCRYPT_KEY」那条在单独进程里跑，不定义密钥
        if (!defined('ENCRYPT_KEY') && $this->name() !== 'testWithoutAnEncryptKeyNewSecretsAreRefusedAndOldValuesKept') {
            define('ENCRYPT_KEY', 'unit-test-encrypt-key-0123456789');
        }
        parent::setUp();
    }

    /**
     * v2.2：没有 ENCRYPT_KEY 时新密钥一律存不进去（不再降级存明文），已存的旧明文照常读、原样保留；
     * 失败的修改不动原值；异常消息和错误日志都不含密钥。
     *
     * @runInSeparateProcess @preserveGlobalState disabled
     */
    public function testWithoutAnEncryptKeyNewSecretsAreRefusedAndOldValuesKept(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'secret-log-');
        ini_set('error_log', $log);
        try {
            self::assertFalse(defined('ENCRYPT_KEY'));
            self::assertFalse(SecretStore::available());
            self::assertSame('key', SecretStore::unavailableReason());

            $refused = static function (callable $write, string $secret): void {
                try {
                    $write();
                    self::fail('a secret was written without encryption');
                } catch (SecretStoreException $e) {
                    self::assertSame('key', $e->reason);
                    self::assertSame('secret_store_save_blocked_key', $e->userMessageKey());
                    self::assertStringNotContainsString($secret, $e->getMessage());
                }
            };
            $refused(static fn () => SecretStore::seal('sk-plain-new'), 'sk-plain-new');
            $refused(static fn () => AiService::encryptKey('sk-ai-new-0000'), 'sk-ai-new-0000');

            // 旧明文（2.1.0 及以前、或 2.1.1–2.1.3 降级存的）照常读出；改它失败时原值不变
            db()->insert('settings', ['key' => 'smtp_pass', 'value' => 'old-mail-pass']);
            $refused(static fn () => SecretStore::put('smtp_pass', 'new-mail-pass'), 'new-mail-pass');
            settingModel()->clearCache();
            $GLOBALS['_test_config'] = ['smtp_pass' => 'old-mail-pass'];
            self::assertSame('old-mail-pass', SecretStore::get('smtp_pass'), '旧明文照常可用');
            self::assertSame('old-mail-pass', (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['smtp_pass']));
            self::assertSame(['smtp_pass'], SecretStore::plaintextCoreSecrets(), '站点健康仍会列出它');
            self::assertSame(0, SecretStore::sealLegacyCoreSecrets(), '没有密钥时迁移什么也不做');

            // AI 配置：新增一套、给旧配置换密钥都整体不落库，旧密钥不变
            settingModel()->set('ai_provider', 'openai');
            settingModel()->set('ai_api_key', 'sk-old-legacy-7777');
            $refused(static fn () => AiProfiles::save(0, ['name' => 'New', 'provider' => 'deepseek'], 'sk-deep-new-1111'), 'sk-deep-new-1111');
            $refused(static fn () => AiProfiles::save(1, ['name' => 'Renamed', 'provider' => 'openai'], 'sk-openai-new-2222'), 'sk-openai-new-2222');
            settingModel()->clearCache();
            self::assertSame('', (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['ai_profiles']), '配置没有落库');
            self::assertSame('sk-old-legacy-7777', AiProfiles::plainKey(1));

            // 清空不需要加密，照常可以做
            SecretStore::put('smtp_pass', '');
            self::assertSame('', (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['smtp_pass']));

            $logged = (string) file_get_contents($log);
            foreach (['sk-plain-new', 'sk-ai-new-0000', 'new-mail-pass', 'old-mail-pass', 'sk-deep-new-1111', 'sk-openai-new-2222', 'sk-old-legacy-7777'] as $secret) {
                self::assertStringNotContainsString($secret, $logged);
            }
        } finally {
            unset($GLOBALS['_test_config']);
            @unlink($log);
        }
    }

    public function testFailureMessagesNameTheReasonNotTheSecret(): void
    {
        self::assertSame('secret_store_save_blocked_cipher', (new SecretStoreException('cipher'))->userMessageKey());
        $failed = new SecretStoreException(SecretStoreException::FAILED);
        self::assertSame('secret_store_save_failed', $failed->userMessageKey());
        self::assertSame('secret_store_failed', $failed->getMessage());
        foreach (['secret_store_save_blocked_key', 'secret_store_save_blocked_cipher', 'secret_store_save_failed', 'secret_store_unavailable_notice', 'health_secret_store_blocked_key', 'health_secret_store_blocked_cipher'] as $key) {
            foreach (['zh-CN', 'en', 'ja', 'zh-TW'] as $lang) {
                $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
                self::assertNotSame('', (string) ($strings[$key] ?? ''), $lang . ' ' . $key);
            }
        }
    }

    public function testPutReplacesTheStoredValueWithAnEnvelope(): void
    {
        db()->insert('settings', ['key' => 'smtp_pass', 'value' => 'legacy-plain']);
        SecretStore::put('smtp_pass', 'rotated-pass');
        settingModel()->clearCache();
        $stored = (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['smtp_pass']);
        self::assertTrue(SecretStore::isSealed($stored));
        self::assertStringNotContainsString('rotated-pass', $stored);
        self::assertSame('rotated-pass', SecretStore::open($stored));
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT NOT NULL DEFAULT \'basic\', "key" TEXT NOT NULL UNIQUE, value TEXT, name TEXT, tip TEXT, type TEXT)',
        ];
    }

    public function testSealedValuesRoundTripWithFreshNonces(): void
    {
        self::assertTrue(SecretStore::available());
        $a = SecretStore::seal('smtp-secret-123');
        $b = SecretStore::seal('smtp-secret-123');
        self::assertStringStartsWith(SecretStore::PREFIX, $a);
        self::assertNotSame($a, $b, '每次随机 nonce，同一明文密文不同');
        self::assertStringNotContainsString('smtp-secret-123', $a);
        self::assertSame('smtp-secret-123', SecretStore::open($a));
        self::assertSame($a, SecretStore::seal($a), '已是信封不再套一层');
        self::assertSame('', SecretStore::seal(''));
    }

    public function testTamperedEnvelopesReadAsEmptyNotAsCiphertext(): void
    {
        $sealed = SecretStore::seal('api-key-abcdef');
        $raw = base64_decode(substr($sealed, strlen(SecretStore::PREFIX)), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        self::assertSame('', SecretStore::open(SecretStore::PREFIX . base64_encode($raw)));
        self::assertSame('', SecretStore::open(SecretStore::PREFIX . 'not-base64!!'));
    }

    public function testLegacyFormatsStayReadable(): void
    {
        // 2.0.x AI 密钥：AES-128-CBC，固定 IV
        $legacy = openssl_encrypt('sk-legacy-key-9876', 'AES-128-CBC', ENCRYPT_KEY, 0, substr(md5(ENCRYPT_KEY), 0, 16));
        self::assertSame('sk-legacy-key-9876', SecretStore::open((string) $legacy, true));
        self::assertSame('sk-legacy-key-9876', AiService::decryptKey((string) $legacy));
        // 更早的明文 AI 密钥 / 明文 SMTP 密码
        self::assertSame('sk-plain', AiService::decryptKey('sk-plain'));
        self::assertSame('plain-pass', SecretStore::open('plain-pass'));
        // 新写入一律是信封，且旧入口能读
        $sealed = AiService::encryptKey('sk-new');
        self::assertTrue(SecretStore::isSealed($sealed));
        self::assertSame('sk-new', AiService::decryptKey($sealed));
    }

    public function testMaskedShowsOnlyTheLastFourCharacters(): void
    {
        self::assertSame('', SecretStore::masked(''));
        self::assertSame('****wxyz', SecretStore::masked('sk-abcdefghijwxyz'));
        self::assertSame('****', SecretStore::masked('short'), '太短的不露任何字符');
    }

    public function testMigrationSealsCoreSecretsOnce(): void
    {
        $legacy = (string) openssl_encrypt('sk-ai-legacy', 'AES-128-CBC', ENCRYPT_KEY, 0, substr(md5(ENCRYPT_KEY), 0, 16));
        db()->insert('settings', ['key' => 'ai_api_key', 'value' => $legacy]);
        db()->insert('settings', ['key' => 'smtp_pass', 'value' => 'mail-pass']);
        db()->insert('settings', ['key' => 'seo_baidu_token', 'value' => 'plugin-owned']);
        // 2.1.1 站点健康：能加密时报出还没封装的核心密钥（旧 CBC 格式也算），插件的不算
        self::assertSame('', SecretStore::unavailableReason());
        self::assertSame(['ai_api_key', 'smtp_pass'], SecretStore::plaintextCoreSecrets());

        self::assertSame(2, SecretStore::sealLegacyCoreSecrets());
        self::assertSame([], SecretStore::plaintextCoreSecrets());
        $stored = static fn (string $key): string => (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [$key]);
        self::assertTrue(SecretStore::isSealed($stored('ai_api_key')));
        self::assertSame('sk-ai-legacy', SecretStore::open($stored('ai_api_key'), true));
        self::assertSame('mail-pass', SecretStore::open($stored('smtp_pass')));
        self::assertSame('plugin-owned', $stored('seo_baidu_token'), '插件自己的密钥由插件保存时封装，核心迁移不碰');
        self::assertSame(0, SecretStore::sealLegacyCoreSecrets(), '幂等');
    }
}
