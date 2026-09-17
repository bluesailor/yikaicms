<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/PluginInstaller.php';

final class PluginInstallerTest extends TestCase
{
    private string $root;
    private string $plugins;
    private string $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yikai-plugin-install-' . bin2hex(random_bytes(8));
        $this->plugins = $this->root . '/plugins';
        $this->storage = $this->root . '/storage';
        mkdir($this->plugins, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testMarketInstallUpdatePersistsOriginAndRetainsBackup(): void
    {
        $installer = $this->installer();
        $registered = [];
        $register = static function (string $slug) use (&$registered): void { $registered[] = $slug; };
        $first = $installer->install($this->package(), $register, 'sample', '1.0.0', 'community');
        self::assertTrue($first['ok'], $first['code']);
        $second = $installer->install($this->package('2.0.0'), $register, 'sample', '2.0.0', 'community');
        self::assertTrue($second['ok'], $second['code']);
        self::assertSame(['sample', 'sample'], $registered);
        self::assertSame('2.0.0', file_get_contents($this->plugins . '/sample/main.php'));
        self::assertSame('1.0.0', file_get_contents($second['backup'] . '/main.php'));
        $receipt = json_decode(file_get_contents($this->plugins . '/sample/.yikai-market-origin.json'), true);
        self::assertSame('community', $receipt['origin']);
        self::assertSame('update.yikaicms.com', $receipt['provider']);
        self::assertSame('2.0.0', $receipt['version']);
    }

    public function testIdentityMismatchNeverTouchesExistingPluginOrRegistration(): void
    {
        $this->seedLocal();
        foreach ([['another', '1.0.0'], ['sample', '9.0.0']] as [$slug, $version]) {
            $result = $this->installer()->install($this->package(), static function (): void {
                self::fail('Identity must be checked before persistence.');
            }, $slug, $version);
            self::assertSame('mismatch', $result['code']);
            self::assertSame('old', file_get_contents($this->plugins . '/sample/main.php'));
        }
        self::assertDirectoryDoesNotExist($this->storage);
    }

    public function testUnsafeMultiRootAndForgedReceiptPackagesAreRejected(): void
    {
        $this->seedLocal();
        foreach (['other/main.php', 'root.php', 'sample/../escape.php', 'sample/link:stream',
            'sample/.yikai-market-origin.json', 'sample/.yikai-market-origin.json.',
            'sample/CON.php', 'sample/sub\\file.php', 'sample/sub//file.php', 'sample/MAIN.php'] as $entry) {
            $result = $this->installer()->install($this->package('2.0.0', [$entry => 'bad']), static function (): void {
                self::fail('Unsafe archives must not register.');
            });
            self::assertFalse($result['ok'], $entry);
            self::assertSame('old', file_get_contents($this->plugins . '/sample/main.php'));
        }
    }

    public function testArchiveSymlinkIsRejected(): void
    {
        $path = $this->package('2.0.0', ['sample/link' => '../../outside']);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->setExternalAttributesName('sample/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        self::assertSame('unsafe', $this->installer()->install($path, static function (): void {})['code']);
        self::assertDirectoryDoesNotExist($this->plugins . '/sample');
    }

    public function testExtractionFailureKeepsOldDirectory(): void
    {
        $this->seedLocal();
        $installer = new PluginInstaller($this->plugins, $this->storage, null,
            static function (ZipArchive $zip, string $to): bool { $zip->extractTo($to); return false; });
        $result = $installer->install($this->package(), static function (): void { self::fail('No registration after failed extraction.'); });
        self::assertSame('extract', $result['code']);
        self::assertSame('old', file_get_contents($this->plugins . '/sample/main.php'));
        self::assertSame([], glob($this->storage . '/plugin-staging/*'));
    }

    public function testActivationMoveFailureRestoresOldDirectory(): void
    {
        $this->seedLocal();
        $installer = new PluginInstaller($this->plugins, $this->storage,
            static fn (string $from, string $to): bool => str_contains($from, '/plugin-staging/') ? false : rename($from, $to));
        $result = $installer->install($this->package(), static function (): void {});
        self::assertSame('replace', $result['code']);
        self::assertSame('old', file_get_contents($this->plugins . '/sample/main.php'));
    }

    public function testRegistrationFailureRestoresFilesAndOriginalReceipt(): void
    {
        $installer = $this->installer();
        $installer->install($this->package(), static function (): void {}, 'sample', '1.0.0', 'official');
        $receipt = file_get_contents($this->plugins . '/sample/.yikai-market-origin.json');
        $result = $installer->install($this->package('2.0.0'), static function (): void {
            throw new RuntimeException('registration_failed');
        }, 'sample', '2.0.0', 'official');
        self::assertFalse($result['ok']);
        self::assertSame('1.0.0', file_get_contents($this->plugins . '/sample/main.php'));
        self::assertSame($receipt, file_get_contents($this->plugins . '/sample/.yikai-market-origin.json'));
    }

    public function testFailedRecoveryLeavesBackupForManualRecovery(): void
    {
        $this->seedLocal();
        $installer = new PluginInstaller($this->plugins, $this->storage,
            static fn (string $from, string $to): bool => str_contains($to, '/plugin-backup/') && rename($from, $to));
        $result = $installer->install($this->package(), static function (): void {});
        self::assertSame('rollback_failed', $result['code']);
        self::assertSame('old', file_get_contents($result['backup'] . '/main.php'));
    }

    public function testOriginChangeAndLegacyInstallCannotBeOverwrittenByMarket(): void
    {
        $installer = $this->installer();
        $installer->install($this->package(), static function (): void {}, 'sample', '1.0.0', 'official');
        $result = $installer->install($this->package('2.0.0'), static function (): void {}, 'sample', '2.0.0', 'community');
        self::assertSame('origin_changed', $result['code']);
        // 没有回执的存量插件（1.20.0 之前安装）：社区条目不得接管
        unlink($this->plugins . '/sample/.yikai-market-origin.json');
        $result = $installer->install($this->package('2.0.0'), static function (): void {}, 'sample', '2.0.0', 'community');
        self::assertSame('origin_unknown', $result['code']);
        self::assertSame('1.0.0', file_get_contents($this->plugins . '/sample/main.php'));
    }

    /** 首次信任：无回执的存量插件可由官方目录更新一次，旧目录进备份，并写入 official 回执。 */
    public function testLegacyPluginWithoutReceiptTrustsOfficialCatalogOnceAndRecordsIt(): void
    {
        $installer = $this->installer();
        $installer->install($this->package(), static function (): void {}, 'sample', '1.0.0', 'official');
        unlink($this->plugins . '/sample/.yikai-market-origin.json');

        $result = $installer->install($this->package('2.0.0'), static function (): void {}, 'sample', '2.0.0', 'official');
        self::assertTrue($result['ok'], $result['code']);
        self::assertSame('2.0.0', file_get_contents($this->plugins . '/sample/main.php'));
        self::assertSame('1.0.0', file_get_contents($result['backup'] . '/main.php'));
        $receipt = json_decode((string) file_get_contents($this->plugins . '/sample/.yikai-market-origin.json'), true);
        self::assertSame(['plugin', 'official', '2.0.0'], [$receipt['kind'], $receipt['origin'], $receipt['version']]);
        // 回执写入后按记录比对：社区条目不能再接管
        $result = $installer->install($this->package('3.0.0'), static function (): void {}, 'sample', '3.0.0', 'community');
        self::assertSame('origin_changed', $result['code']);
    }

    /** 首次信任不覆盖手动上传：local 回执或损坏回执仍拒绝官方目录。 */
    public function testLocalOrCorruptReceiptStillBlocksOfficialCatalog(): void
    {
        $installer = $this->installer();
        self::assertTrue($installer->install($this->package(), static function (): void {})['ok']);
        $result = $installer->install($this->package('2.0.0'), static function (): void {}, 'sample', '2.0.0', 'official');
        self::assertSame('origin_unknown', $result['code']);
        file_put_contents($this->plugins . '/sample/.yikai-market-origin.json', '{"origin":"official"');
        $result = $installer->install($this->package('2.0.0'), static function (): void {}, 'sample', '2.0.0', 'official');
        self::assertSame('origin_unknown', $result['code']);
        self::assertSame('1.0.0', file_get_contents($this->plugins . '/sample/main.php'));
    }

    public function testManualUploadDoesNotInheritMarketProvenance(): void
    {
        $installer = $this->installer();
        $installer->install($this->package(), static function (): void {}, 'sample', '1.0.0', 'official');
        self::assertTrue($installer->install($this->package('2.0.0'), static function (): void {})['ok']);
        $this->expectExceptionMessage('origin_unknown');
        $installer->assertOrigin('sample', 'official');
    }

    public function testSamePluginConcurrentInstallIsRejected(): void
    {
        $package = $this->package();
        $installer = new PluginInstaller($this->plugins, $this->storage, null,
            function (ZipArchive $zip, string $to) use ($package): bool {
                $nested = $this->installer()->install($package, static function (): void {});
                self::assertSame('busy', $nested['code']);
                return $zip->extractTo($to);
            });
        self::assertTrue($installer->install($package, static function (): void {})['ok']);
    }

    public function testOriginChangeDuringExtractionIsRecheckedBeforeReplacement(): void
    {
        $this->installer()->install($this->package(), static function (): void {}, 'sample', '1.0.0', 'official');
        $receiptPath = $this->plugins . '/sample/.yikai-market-origin.json';
        $installer = new PluginInstaller($this->plugins, $this->storage, null,
            static function (ZipArchive $zip, string $to) use ($receiptPath): bool {
                $receipt = json_decode(file_get_contents($receiptPath), true);
                $receipt['origin'] = 'community';
                file_put_contents($receiptPath, json_encode($receipt));
                return $zip->extractTo($to);
            });
        $result = $installer->install($this->package('2.0.0'), static function (): void {}, 'sample', '2.0.0', 'official');
        self::assertSame('origin_changed', $result['code']);
        self::assertSame('1.0.0', file_get_contents($this->plugins . '/sample/main.php'));
    }

    private function installer(): PluginInstaller { return new PluginInstaller($this->plugins, $this->storage); }

    private function seedLocal(): void
    {
        mkdir($this->plugins . '/sample');
        file_put_contents($this->plugins . '/sample/main.php', 'old');
    }

    private function package(string $version = '1.0.0', array $extra = []): string
    {
        $path = $this->root . '/' . bin2hex(random_bytes(5)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addFromString('sample/plugin.json', json_encode(['name' => 'Sample', 'version' => $version], JSON_THROW_ON_ERROR));
        $zip->addFromString('sample/main.php', $version);
        foreach ($extra as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        return $path;
    }

    private function removeTree(string $dir): void
    {
        if (is_file($dir) || is_link($dir)) { unlink($dir); return; }
        if (!is_dir($dir)) return;
        foreach (new DirectoryIterator($dir) as $entry) if (!$entry->isDot()) $this->removeTree($entry->getPathname());
        rmdir($dir);
    }
}
