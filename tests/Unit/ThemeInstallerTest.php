<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/ThemeInstaller.php';
require_once ROOT_PATH . '/includes/ThemeMarket.php';

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
        self::assertStringContainsString('->removeInstalled($slug, currentTheme())', $source);
    }

    public function testInactiveInstalledThemeCanBeRemovedWithoutTouchingSiblings(): void
    {
        $this->writeInstalledTheme('default', 'default-header');
        $this->writeInstalledTheme('business', 'business-header');

        $result = (new ThemeInstaller($this->themesRoot, $this->storageRoot))
            ->removeInstalled('business', 'default');

        self::assertTrue($result['ok']);
        self::assertSame('deleted', $result['code']);
        self::assertDirectoryDoesNotExist($this->themesRoot . '/business');
        self::assertFileExists($this->themesRoot . '/default/layouts/header.php');
    }

    public function testDefaultActiveInvalidAndMissingThemesAreNotRemoved(): void
    {
        $this->writeInstalledTheme('default', 'default-header');
        $this->writeInstalledTheme('business', 'business-header');
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot);

        self::assertSame('default_protected', $installer->removeInstalled('default', 'business')['code']);
        self::assertSame('active_protected', $installer->removeInstalled('business', 'business')['code']);
        self::assertSame('bad_slug', $installer->removeInstalled('../business', 'default')['code']);
        self::assertSame('not_found', $installer->removeInstalled('minimal', 'default')['code']);
        self::assertDirectoryExists($this->themesRoot . '/default');
        self::assertDirectoryExists($this->themesRoot . '/business');
    }

    public function testDeletionFailureIsReportedAndKeepsTheThemeVisible(): void
    {
        $this->writeInstalledTheme('business', 'business-header');
        $installer = new ThemeInstaller(
            $this->themesRoot,
            $this->storageRoot,
            null,
            null,
            null,
            static fn (string $_directory): bool => false
        );

        $result = $installer->removeInstalled('business', 'default');

        self::assertFalse($result['ok']);
        self::assertSame('delete_failed', $result['code']);
        self::assertDirectoryExists($this->themesRoot . '/business');
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

    public function testManagedThemeUpdateRetainsOriginWithBackup(): void
    {
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot);
        self::assertTrue($installer->install($this->themeZip('business', 'old'), 'business', '1.0.0', 'official')['ok']);
        $updated = $installer->install($this->themeZip('business', 'new', '1.1.0'), 'business', '1.1.0', 'official');
        self::assertTrue($updated['ok'], $updated['code']);
        self::assertSame('old', file_get_contents($updated['backup'] . '/layouts/header.php'));
        $receipt = json_decode(file_get_contents($this->themesRoot . '/business/' . MarketInstallOrigin::FILE), true);
        self::assertSame('official', $receipt['origin']);
        self::assertSame('theme', $receipt['kind']);
        self::assertSame('1.1.0', $receipt['version']);
        self::assertSame('1.0.0', json_decode(file_get_contents($updated['backup'] . '/' . MarketInstallOrigin::FILE), true)['version']);
    }

    public function testThemeSourceSwitchAndUnknownOriginNeverOverwrite(): void
    {
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot);
        self::assertTrue($installer->install($this->themeZip('business', 'old'), 'business', '1.0.0', 'community')['ok']);
        $zip = $this->themeZip('business', 'new', '1.1.0');
        self::assertSame('origin_changed', $installer->install($zip, 'business', '1.1.0', 'official')['code']);
        unlink($this->themesRoot . '/business/' . MarketInstallOrigin::FILE);
        self::assertSame('origin_unknown', $installer->install($zip, 'business', '1.1.0', 'community')['code']);
        self::assertSame('old', file_get_contents($this->themesRoot . '/business/layouts/header.php'));

        // 首次信任：无回执的存量主题可由官方目录更新，旧主题进备份并写入 official 回执
        $updated = $installer->install($zip, 'business', '1.1.0', 'official');
        self::assertTrue($updated['ok'], $updated['code']);
        self::assertSame('new', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
        self::assertSame('old', file_get_contents($updated['backup'] . '/layouts/header.php'));
        $receipt = json_decode(file_get_contents($this->themesRoot . '/business/' . MarketInstallOrigin::FILE), true);
        self::assertSame(['theme', 'official'], [$receipt['kind'], $receipt['origin']]);
    }

    public function testFailedManagedThemeUpdateRestoresOriginAndFiles(): void
    {
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot);
        self::assertTrue($installer->install($this->themeZip('business', 'old'), 'business', '1.0.0', 'community')['ok']);
        $before = file_get_contents($this->themesRoot . '/business/' . MarketInstallOrigin::FILE);
        $calls = 0;
        $failing = new ThemeInstaller($this->themesRoot, $this->storageRoot, null, null,
            static function () use (&$calls): array {
                return ['errors' => ++$calls === 2 ? ['final validation failed'] : [], 'warnings' => []];
            });
        $result = $failing->install($this->themeZip('business', 'new', '1.1.0'), 'business', '1.1.0', 'community');
        self::assertSame('final_invalid', $result['code']);
        self::assertSame('old', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
        self::assertSame($before, file_get_contents($this->themesRoot . '/business/' . MarketInstallOrigin::FILE));
    }

    public function testLocalThemeInstallDoesNotRetainManagedIdentity(): void
    {
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot);
        self::assertTrue($installer->install($this->themeZip('business', 'old'), 'business', '1.0.0', 'official')['ok']);
        self::assertTrue($installer->install($this->themeZip('business', 'local', '1.1.0'))['ok']);
        $this->expectExceptionMessage('origin_unknown');
        $installer->assertOrigin('business', 'official');
    }

    public function testThemePackageCannotSupplyReceipt(): void
    {
        foreach ([MarketInstallOrigin::FILE, strtoupper(MarketInstallOrigin::FILE) . '.'] as $entry) {
            $path = $this->themeZip('business', 'new');
            $zip = new ZipArchive();
            $zip->open($path);
            $zip->addFromString('business/' . $entry, '{"origin":"official"}');
            $zip->close();
            $result = (new ThemeInstaller($this->themesRoot, $this->storageRoot))->install($path);
            self::assertSame('unsafe', $result['code']);
            self::assertDirectoryDoesNotExist($this->themesRoot . '/business');
        }
    }

    public function testThemeInstallLockAndOriginRecheckAfterExtraction(): void
    {
        $plain = new ThemeInstaller($this->themesRoot, $this->storageRoot);
        self::assertTrue($plain->install($this->themeZip('business', 'old'), 'business', '1.0.0', 'official')['ok']);
        $path = $this->themeZip('business', 'new', '1.1.0');
        $file = $this->themesRoot . '/business/' . MarketInstallOrigin::FILE;
        $installer = new ThemeInstaller($this->themesRoot, $this->storageRoot, null,
            static function (ZipArchive $zip, string $destination) use ($plain, $path, $file): bool {
                self::assertSame('busy', $plain->install($path, 'business', '1.1.0', 'official')['code']);
                $receipt = json_decode(file_get_contents($file), true);
                $receipt['origin'] = 'community';
                file_put_contents($file, json_encode($receipt));
                return $zip->extractTo($destination);
            });
        self::assertSame('origin_changed', $installer->install($path, 'business', '1.1.0', 'official')['code']);
        self::assertSame('old', file_get_contents($this->themesRoot . '/business/layouts/header.php'));
    }

    public function testCatalogUsesReceiptKindAndSourceBeforeOfferingUpdate(): void
    {
        $this->writeInstalledTheme('business', 'old');
        MarketInstallOrigin::write($this->themesRoot . '/business', 'plugin', 'business', '1.0.0', 'official');
        $catalog = ['data' => ['themes' => [['slug' => 'business', 'download_url' => 'signed-url']]]];
        $blocked = ThemeMarket::withInstalledOrigins($catalog, $this->themesRoot)['data']['themes'][0];
        self::assertSame('origin_unknown', $blocked['locked_reason']);
        self::assertSame('', $blocked['download_url']);
        MarketInstallOrigin::write($this->themesRoot . '/business', 'theme', 'business', '1.0.0', 'official');
        $allowed = ThemeMarket::withInstalledOrigins($catalog, $this->themesRoot)['data']['themes'][0];
        self::assertFalse($allowed['download_blocked']);
        self::assertSame('signed-url', $allowed['download_url']);
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
