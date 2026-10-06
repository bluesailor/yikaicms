<?php
/** v2.1 统一密钥存储：信封往返、篡改与换钥匙读不出、旧格式只读兼容、末四位掩码、迁移封装核心密钥。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiService;
use SecretStore;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/SecretStore.php';
require_once ROOT_PATH . '/includes/AiService.php';

final class SecretStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ENCRYPT_KEY')) {
            define('ENCRYPT_KEY', 'unit-test-encrypt-key-0123456789');
        }
        parent::setUp();
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT NOT NULL DEFAULT \'basic\', "key" TEXT NOT NULL, value TEXT)',
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

        self::assertSame(2, SecretStore::sealLegacyCoreSecrets());
        $stored = static fn (string $key): string => (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [$key]);
        self::assertTrue(SecretStore::isSealed($stored('ai_api_key')));
        self::assertSame('sk-ai-legacy', SecretStore::open($stored('ai_api_key'), true));
        self::assertSame('mail-pass', SecretStore::open($stored('smtp_pass')));
        self::assertSame('plugin-owned', $stored('seo_baidu_token'), '插件自己的密钥由插件保存时封装，核心迁移不碰');
        self::assertSame(0, SecretStore::sealLegacyCoreSecrets(), '幂等');
    }
}
