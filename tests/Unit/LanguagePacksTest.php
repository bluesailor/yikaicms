<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguagePacks.php';
require_once ROOT_PATH . '/tools/LangPackZip.php';

/**
 * 语言包是会被 require 执行的 PHP：这里逐条守住「不签名 / 签错 / 换语言 / 夹带文件 / 冒充别的签名」都装不进去，
 * 以及「装失败不碰现有文件」「启用中的不能卸」「内置的不算语言包」。
 */
final class LanguagePacksTest extends TestCase
{
    private string $root;
    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yk-langpack-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/lang', 0777, true);
        mkdir($this->root . '/storage', 0777, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'openssl 不可用');
        openssl_pkey_export($key, $pem);
        $this->privateKey = (string) $pem;
        $this->publicKey = (string) openssl_pkey_get_details($key)['key'];
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->root . '/lang/{,.}*', GLOB_BRACE) ?: []), ...(glob($this->root . '/storage/*') ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->root . '/lang');
        @rmdir($this->root . '/storage');
        @rmdir($this->root);
    }

    public function testBundledLanguagesAreNotPacksAndEverythingElseIs(): void
    {
        self::assertSame(['zh-CN', 'en', 'ja'], LanguagePacks::BUNDLED);
        self::assertSame(array_values(array_diff(LanguageRegistry::codes(), LanguagePacks::BUNDLED)), LanguagePacks::downloadable());
        self::assertCount(15, LanguagePacks::downloadable());
        self::assertSame(
            'https://down.yikai.cn/soft/yikaicms/lang/2.1.1/yikaicms-lang-ru-2.1.1.zip',
            LanguagePacks::url('ru', '2.1.1')
        );
    }

    public function testSignedPackInstallsAndIsRecorded(): void
    {
        $result = LanguagePacks::installFromZip($this->root, $this->pack('ru', '2.1.1'), '2.1.1', $this->publicKey);

        self::assertSame(['code' => 'ru', 'version' => '2.1.1'], $result);
        self::assertSame(['hello' => 'Привет'], require $this->root . '/lang/ru.php');
        self::assertSame('2.1.1', LanguagePacks::manifest($this->root)['ru']['version']);
        self::assertSame(['ru'], LanguagePacks::installed($this->root));
        self::assertSame([], LanguagePacks::outdated($this->root, '2.1.1'));
        self::assertSame(['ru'], LanguagePacks::outdated($this->root, '2.1.2'), '升级后旧版本语言包要提示更新');
        self::assertSame([], glob($this->root . '/lang/.*.tmp') ?: [], '临时文件不得残留');
    }

    public function testLegacyFileWithoutRecordCountsAsOutdated(): void
    {
        // 2.1 之前随安装包来的 lang/ru.php：没有记录，升级后按「版本未知」更新
        file_put_contents($this->root . '/lang/ru.php', "<?php return ['a' => 'b'];");
        self::assertSame(['ru'], LanguagePacks::outdated($this->root, '2.1.1'));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function rejectedPacks(): iterable
    {
        yield 'unsigned' => ['unsigned', 'bad_signature'];
        yield 'signed by another key' => ['foreign_key', 'bad_signature'];
        yield 'content changed after signing' => ['tampered', 'hash_mismatch'];
        yield 'signature of a CMS release (version|hash)' => ['release_signature', 'bad_signature'];
        yield 'extra file in zip' => ['extra_entry', 'bad_zip'];
        yield 'bundled language' => ['bundled', 'unknown_language'];
        yield 'unregistered code' => ['unregistered', 'unknown_language'];
        yield 'newer than this CMS' => ['newer', 'newer_than_cms'];
        yield 'not a translation array' => ['not_array', 'bad_zip'];
    }

    /** @dataProvider rejectedPacks */
    public function testRejectedPackLeavesExistingFileUntouched(string $case, string $reason): void
    {
        $existing = "<?php return ['hello' => 'old'];";
        file_put_contents($this->root . '/lang/ru.php', $existing);

        $zip = match ($case) {
            'unsigned' => $this->pack('ru', '2.1.1', sign: false),
            'foreign_key' => $this->pack('ru', '2.1.1', key: $this->otherKey()),
            'tampered' => $this->pack('ru', '2.1.1', tamper: true),
            'release_signature' => $this->pack('ru', '2.1.1', releaseStyleSignature: true),
            'extra_entry' => $this->pack('ru', '2.1.1', extra: ['../../config/config.php' => '<?php // x']),
            'bundled' => $this->pack('en', '2.1.1'),
            'unregistered' => $this->pack('xx', '2.1.1'),
            'newer' => $this->pack('ru', '2.1.2'),
            'not_array' => $this->pack('ru', '2.1.1', php: '<?php return "nope";'),
        };

        try {
            LanguagePacks::installFromZip($this->root, $zip, '2.1.1', $this->publicKey);
            self::fail('应拒装：' . $case);
        } catch (LanguagePackException $e) {
            self::assertSame($reason, $e->reason());
        }
        self::assertSame($existing, file_get_contents($this->root . '/lang/ru.php'));
        self::assertSame([], LanguagePacks::manifest($this->root));
        self::assertSame([], glob($this->root . '/lang/.*.tmp') ?: []);
    }

    public function testExpectedLanguageMustMatch(): void
    {
        $this->expectExceptionObject(new LanguagePackException('wrong_language', 'de'));
        LanguagePacks::installFromZip($this->root, $this->pack('de', '2.1.1'), '2.1.1', $this->publicKey, 'ru');
    }

    public function testOlderPackInstallsForOfflineUse(): void
    {
        $result = LanguagePacks::installFromZip($this->root, $this->pack('ru', '2.1.0'), '2.1.1', $this->publicKey);
        self::assertSame('2.1.0', $result['version']);
        self::assertSame(['ru'], LanguagePacks::outdated($this->root, '2.1.1'));
    }

    public function testDownloadUsesTheFixedOfficialAddress(): void
    {
        $asked = [];
        $zip = $this->pack('vi', '2.1.1');
        LanguagePacks::download($this->root, 'vi', '2.1.1', $this->publicKey, static function (string $url, int $max) use (&$asked, $zip): ?string {
            $asked[] = [$url, $max];
            return $zip;
        });
        self::assertSame([[LanguagePacks::url('vi', '2.1.1'), LanguagePacks::MAX_DOWNLOAD_BYTES]], $asked);

        $this->expectExceptionObject(new LanguagePackException('download_failed', LanguagePacks::url('ko', '2.1.1')));
        LanguagePacks::download($this->root, 'ko', '2.1.1', $this->publicKey, static fn(): ?string => null);
    }

    public function testLanguageInUseCannotBeUninstalled(): void
    {
        LanguagePacks::installFromZip($this->root, $this->pack('ru', '2.1.1'), '2.1.1', $this->publicKey);
        try {
            LanguagePacks::uninstall($this->root, 'ru', ['zh-CN', 'ru']);
            self::fail('启用中的语言不得卸载');
        } catch (LanguagePackException $e) {
            self::assertSame('in_use', $e->reason());
        }
        self::assertFileExists($this->root . '/lang/ru.php');

        LanguagePacks::uninstall($this->root, 'ru', ['zh-CN']);
        self::assertFileDoesNotExist($this->root . '/lang/ru.php');
        self::assertSame([], LanguagePacks::manifest($this->root));
    }

    /** @param array<string,string> $extra */
    private function pack(
        string $code,
        string $version,
        bool $sign = true,
        ?string $key = null,
        bool $tamper = false,
        bool $releaseStyleSignature = false,
        array $extra = [],
        string $php = "<?php return ['hello' => 'Привет'];",
    ): string {
        $sha = hash('sha256', $php);
        $signature = '';
        if ($sign) {
            $message = $releaseStyleSignature ? $version . '|sha256:' . $sha : LanguagePacks::canonical($code, $version, $sha);
            openssl_sign($message, $raw, $key ?? $this->privateKey, OPENSSL_ALGO_SHA256);
            $signature = base64_encode($raw);
        }
        $path = $this->root . '/build-' . bin2hex(random_bytes(3)) . '.zip';
        langPackZip($path, $code, $tamper ? $php . "\n// changed" : $php, [
            'schema' => 1, 'code' => $code, 'cms_version' => $version, 'sha256' => $sha, 'signature' => $signature,
        ]);
        if ($extra !== []) {
            $zip = new ZipArchive();
            $zip->open($path);
            foreach ($extra as $name => $data) {
                $zip->addFromString($name, $data);
            }
            $zip->close();
        }
        $bytes = (string) file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    private function otherKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        return (string) $pem;
    }
}
