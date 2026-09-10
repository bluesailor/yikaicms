<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/ThemeInstaller.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DefaultThemeUpdateTest extends TestCase
{
    private string $root;
    private string $zip;
    private string $signature;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yikai-default-update-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/themes/default/layouts', 0700, true);
        mkdir($this->root . '/storage', 0700, true);
        file_put_contents($this->root . '/themes/default/theme.json', '{"version":"1.0.5"}');
        file_put_contents($this->root . '/themes/default/layouts/header.php', 'customer-original');
        file_put_contents($this->root . '/storage/customer-data.json', '{"keep":true}');
        $meta = ['schema_version'=>1, 'name'=>'Default', 'name_en'=>'Default', 'name_ja'=>'Default',
            'description'=>'Test', 'description_en'=>'Test', 'description_ja'=>'Test',
            'version'=>'1.0.6', 'author'=>'Yikai', 'category'=>'general',
            'requires_cms'=>'>=1.0.0', 'requires_php'=>'>=8.0.0', 'required_plugins'=>[]];
        $this->zip = $this->root . '/package.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->zip, ZipArchive::CREATE));
        $zip->addFromString('default/theme.json', json_encode($meta, JSON_THROW_ON_ERROR));
        $zip->addFromString('default/layouts/header.php', 'official-new');
        $zip->addFromString('default/layouts/footer.php', 'official-footer');
        $zip->close();
        $key = openssl_pkey_new(['private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        define('LICENSE_PUBKEY_B64', preg_replace('/-----[^-]+-----|\s/', '', $details['key']));
        self::assertTrue(openssl_sign('default|1.0.6|sha256:' . hash_file('sha256', $this->zip), $sig, $key, OPENSSL_ALGO_SHA256));
        $this->signature = base64_encode($sig);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    private function installer(?callable $rename = null): ThemeInstaller
    {
        return new ThemeInstaller($this->root . '/themes', $this->root . '/storage', $rename);
    }

    public function testOfficialSignedUpdatePreservesBackupAndNonThemeData(): void
    {
        $result = $this->installer()->install($this->zip, 'default', '1.0.6', $this->signature);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('official-new', file_get_contents($this->root . '/themes/default/layouts/header.php'));
        self::assertSame('customer-original', file_get_contents($result['backup'] . '/layouts/header.php'));
        self::assertSame('{"keep":true}', file_get_contents($this->root . '/storage/customer-data.json'));
        self::assertSame('default_protected', $this->installer()->removeInstalled('default', 'minimal')['code']);
        self::assertSame('default_protected', $this->installer()->install($this->zip, 'default', '1.0.6', $this->signature)['code']);
    }

    public function testUnsignedWrongSignatureWrongContextAndTamperedBytesAreRejected(): void
    {
        foreach ([['', '', ''], ['default', '1.0.6', ''], ['default', '1.0.6', base64_encode('fake')],
            ['minimal', '1.0.6', $this->signature], ['default', '1.0.7', $this->signature]] as $args) {
            self::assertSame('default_protected', $this->installer()->install($this->zip, ...$args)['code']);
        }
        $zip = new ZipArchive();
        $zip->open($this->zip);
        $zip->addFromString('default/layouts/header.php', 'tampered');
        $zip->close();
        self::assertSame('default_protected', $this->installer()->install($this->zip, 'default', '1.0.6', $this->signature)['code']);
        self::assertSame('customer-original', file_get_contents($this->root . '/themes/default/layouts/header.php'));
        self::assertDirectoryDoesNotExist($this->root . '/storage/theme-backup');
    }

    public function testActivationFailureRestoresDefaultInsteadOfLeavingSiteWithoutFallback(): void
    {
        $rename = static fn(string $from, string $to): bool => str_contains(str_replace('\\', '/', $from), '/theme-staging/')
            ? false : rename($from, $to);
        $result = $this->installer($rename)->install($this->zip, 'default', '1.0.6', $this->signature);
        self::assertSame('activate', $result['code']);
        self::assertSame('customer-original', file_get_contents($this->root . '/themes/default/layouts/header.php'));
    }

    public function testDefaultCannotBeInstalledWithoutExistingVersionOrDowngraded(): void
    {
        foreach (['{}', '{"version":"2.0.0"}'] as $meta) {
            file_put_contents($this->root . '/themes/default/theme.json', $meta);
            self::assertSame('default_protected', $this->installer()->install($this->zip, 'default', '1.0.6', $this->signature)['code']);
        }
    }
}
