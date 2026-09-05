<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/ThemeInstaller.php';

#[RequiresPhpExtension('zip')]
final class ThemeInstallerTest extends TestCase
{
    private string $root;
    private string $themesRoot;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yikai-theme-installer-' . bin2hex(random_bytes(5));
        $this->themesRoot = $this->root . '/themes';
        $this->storageRoot = $this->root . '/storage';
        self::assertTrue(mkdir($this->themesRoot, 0700, true));
        self::assertTrue(mkdir($this->storageRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testThemeMarketWritePathUsesTheTransactionalInstallerAndCsrfCheck(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/theme.php');
        self::assertNotFalse($source);

        self::assertStringContainsString('verifyCsrf();', $source);
        self::assertStringContainsString("new ThemeInstaller(ROOT_PATH . '/themes', ROOT_PATH . '/storage')", $source);
        self::assertStringNotContainsString('deleteThemeDir(', $source);
        self::assertStringNotContainsString('$zip->extractTo($themesDir)', $source);
    }

    public function testLocalThemeDeletionIsCsrfCheckedAndProtected(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/theme.php');
        self::assertNotFalse($source);

        $action = strpos($source, "'delete_theme'");
        $csrf = strpos($source, 'verifyCsrf();', (int) $action);
        self::assertIsInt($action);
        self::assertIsInt($csrf);
        self::assertStringContainsString("\$slug === 'default'", $source);
        self::assertStringContainsString("\$slug === \$current", $source);
        self::assertStringContainsString("ROOT_PATH . '/themes/' . \$slug", $source);
    }

    public function testNewThemeIsValidatedInStagingBeforeInstallation(): void
    {
        $zip = $this->themeZip('minimal', 'new-header');

        $result = (new ThemeInstaller($this->themesRoot, $this->storageRoot))->install($zip, 'minimal');

        self::assertTrue($result['ok'], $result['detail']);
        self::assertSame('new-header', file_get_contents($this->themesRoot . '/minimal/layouts/header.php'));
        self::assertSame('', $result['backup']);
        self::assertSame([], glob($this->storageRoot . '/theme-staging/*') ?: []);
    }

    public function testUpgradeRetainsPreviousThemeAsBackup(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');

        $result = (new ThemeInstaller($this->themesRoot, $this->storageRoot))->install($zip, 'business');

        self::assertTrue($result['ok'], $result['detail']);
        self::assertSame('new-header', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
        self::assertDirectoryExists($result['backup']);
        self::assertSame('old-header', file_get_contents($result['backup'] . '/layouts/header.php'));
    }

    public function testExtractionFailureLeavesExistingThemeUntouched(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');
        $installer = new ThemeInstaller(
            $this->themesRoot,
            $this->storageRoot,
            null,
            static fn (ZipArchive $archive, string $destination): bool => $archive->numFiles < 0 && $destination === ''
        );

        $result = $installer->install($zip, 'business');

        self::assertFalse($result['ok']);
        self::assertSame('extract', $result['code']);
        self::assertSame('old-header', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
    }

    public function testActivationFailureRestoresPreviousTheme(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');
        $target = $this->themesRoot . '/business';
        $rename = static function (string $from, string $to) use ($target): bool {
            if (str_contains(str_replace('\\', '/', $from), '/theme-staging/') && $to === $target) {
                return false;
            }
            return rename($from, $to);
        };
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot, $rename);

        $result = $installer->install($zip, 'business');

        self::assertFalse($result['ok']);
        self::assertSame('activate', $result['code']);
        self::assertSame('old-header', file_get_contents($target . '/layouts/header.php'));
    }

    public function testPostInstallValidationFailureRestoresPreviousTheme(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');
        $calls = 0;
        $validator = static function (string $directory, string $slug) use (&$calls): array {
            $calls++;
            if ($calls === 2) {
                return ['errors' => ['simulated final validation failure'], 'warnings' => [], 'meta' => []];
            }
            return ThemeValidator::validateDir($directory, $slug);
        };
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot, null, null, $validator);

        $result = $installer->install($zip, 'business');

        self::assertFalse($result['ok']);
        self::assertSame('final_invalid', $result['code']);
        self::assertSame('old-header', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
    }

    public function testStagingValidationExceptionLeavesExistingThemeUntouched(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');
        $validator = static function (string $directory, string $slug): array {
            throw new RuntimeException('simulated validator failure for ' . $slug . ' at ' . $directory);
        };
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot, null, null, $validator);

        $result = $installer->install($zip, 'business');

        self::assertFalse($result['ok']);
        self::assertSame('staging_invalid', $result['code']);
        self::assertStringContainsString('simulated validator failure', $result['detail']);
        self::assertSame('old-header', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
    }

    public function testBackupMoveFailureDoesNotTouchExistingTheme(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');
        $target = $this->themesRoot . '/business';
        $rename = static fn (string $from, string $to): bool => $from === $target ? false : rename($from, $to);
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot, $rename);

        $result = $installer->install($zip, 'business');

        self::assertFalse($result['ok']);
        self::assertSame('backup_move', $result['code']);
        self::assertSame('old-header', file_get_contents($target . '/layouts/header.php'));
    }

    public function testCleanupFailureRollsBackInstalledTheme(): void
    {
        $this->writeInstalledTheme('business', 'old-header');
        $zip = $this->themeZip('business', 'new-header');
        $failedOnce = false;
        $remove = function (string $directory) use (&$failedOnce): bool {
            if (!$failedOnce && str_contains(str_replace('\\', '/', $directory), '/theme-staging/')) {
                $failedOnce = true;
                return false;
            }
            return $this->removeTreeResult($directory);
        };
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot, null, null, null, $remove);

        $result = $installer->install($zip, 'business');

        self::assertFalse($result['ok']);
        self::assertSame('cleanup', $result['code']);
        self::assertSame('old-header', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
    }

    public function testDefaultThemeAndUnexpectedSlugAreRejectedBeforeExtraction(): void
    {
        $default = (new ThemeInstaller($this->themesRoot, $this->storageRoot))
            ->install($this->themeZip('default', 'replacement'), 'default');
        $mismatch = (new ThemeInstaller($this->themesRoot, $this->storageRoot))
            ->install($this->themeZip('minimal', 'new-header'), 'business');

        self::assertFalse($default['ok']);
        self::assertSame('default_protected', $default['code']);
        self::assertFalse($mismatch['ok']);
        self::assertSame('slug_mismatch', $mismatch['code']);
        self::assertDirectoryDoesNotExist($this->themesRoot . '/minimal');
    }

    public function testUnexpectedCatalogVersionIsRejectedBeforeExtraction(): void
    {
        $result = (new ThemeInstaller($this->themesRoot, $this->storageRoot))
            ->install($this->themeZip('business', 'new-header', '1.0.1'), 'business', '1.0.2');

        self::assertFalse($result['ok']);
        self::assertSame('version_mismatch', $result['code']);
        self::assertDirectoryDoesNotExist($this->themesRoot . '/business');
        self::assertSame([], glob($this->storageRoot . '/theme-staging/*') ?: []);
    }

    private function themeZip(string $slug, string $header, string $version = '1.0.0'): string
    {
        $zipPath = $this->root . '/' . $slug . '-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $meta = [
            'schema_version' => 1,
            'name' => ucfirst($slug),
            'name_en' => ucfirst($slug),
            'name_ja' => ucfirst($slug),
            'description' => 'Test theme',
            'description_en' => 'Test theme',
            'description_ja' => 'Test theme',
            'version' => $version,
            'author' => 'YikaiCMS',
            'category' => 'general',
            'requires_cms' => '>=1.0',
            'requires_php' => '>=8.0',
            'required_plugins' => [],
        ];
        self::assertTrue($zip->addFromString($slug . '/theme.json', json_encode($meta, JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->addFromString($slug . '/layouts/header.php', $header));
        self::assertTrue($zip->addFromString($slug . '/layouts/footer.php', 'footer'));
        self::assertTrue($zip->close());
        return $zipPath;
    }

    private function writeInstalledTheme(string $slug, string $header): void
    {
        $directory = $this->themesRoot . '/' . $slug . '/layouts';
        self::assertTrue(mkdir($directory, 0700, true));
        self::assertNotFalse(file_put_contents(dirname($directory) . '/theme.json', '{}'));
        self::assertNotFalse(file_put_contents($directory . '/header.php', $header));
        self::assertNotFalse(file_put_contents($directory . '/footer.php', 'old-footer'));
    }

    private function removeTree(string $directory): void
    {
        $this->removeTreeResult($directory);
    }

    private function removeTreeResult(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        return rmdir($directory);
    }
}
