<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/tools/ReleaseArtifactSmoke.php';

final class ReleaseArtifactSmokeTest extends TestCase
{
    private string $tempDir;

    /** @var array{required_files:list<string>,generated_files?:list<string>,forbidden_paths:list<string>} */
    private array $manifest;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/yikaicms-artifact-test-' . bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0700, true);
        $this->manifest = require ROOT_PATH . '/config/release-runtime.php';
        $this->copyRuntimeFixture($this->tempDir . '/yikaicms-v9.9.9');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
    }

    public function testRuntimeFixturePassesIncludingChineseSlugProbe(): void
    {
        $smoke = new ReleaseArtifactSmoke($this->manifest);
        self::assertSame([], $smoke->inspectDirectory($this->tempDir . '/yikaicms-v9.9.9'));
    }

    public function testMissingPinyinRuntimeFailsArtifactSmoke(): void
    {
        $root = $this->tempDir . '/yikaicms-v9.9.9';
        unlink($root . '/includes/pinyin/chars.php');

        $errors = (new ReleaseArtifactSmoke($this->manifest))->inspectDirectory($root);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('includes/pinyin/chars.php', implode("\n", $errors));
        self::assertStringContainsString('Chinese slug probe returned an unreadable value', implode("\n", $errors));
    }

    /**
     * 在线升级链路必须整条进必含清单。
     *
     * 升级缺文件比前台缺文件重：前台是页面报错，升级是站点卡在「升到一半」——新入口已落盘、
     * 依赖还没写入，第二轮请求再也起不来（v1.19.4 事故形态）。这条链路此前只有
     * _inline_upgrades.php 一项在清单里，等于没守；v1.19.5 的 UpgradeEntryOrder.php
     * 在 PHP 8.0 上 T_ENUM 致命、演示站实测缺失 StaticHtmlUrlPolicy.php，都因此没被审包拦下。
     */
    public function testUpgradeChainIsCoveredByTheRuntimeManifest(): void
    {
        $chain = [
            'admin/upgrade_online.php',
            'includes/UpgradeRunner.php',
            'includes/UpgradeEntryOrder.php',
            'includes/UpgradeDatabaseRollback.php',
            'includes/UpgradeHealth.php',
            'includes/UpdateChannel.php',
            'includes/UpdatePackageSignature.php',
            'includes/Migrator.php',
            'includes/Backup.php',
            'includes/StaticHtmlUrlPolicy.php',
        ];

        foreach ($chain as $path) {
            self::assertContains(
                $path,
                $this->manifest['required_files'],
                "升级链路文件未登记进 config/release-runtime.php：{$path}。"
                . '缺了它，发行包少这个文件时审包与产物冒烟都不会报，装上升级到一半才会挂。'
            );
        }
    }

    public function testCurrentReleaseRuntimeDependenciesAreExplicitlyCovered(): void
    {
        foreach ([
            'includes/http_response.php',
            'includes/language_request.php',
            'assets/icons/blox-icon-catalog.json',
        ] as $path) {
            self::assertContains($path, $this->manifest['required_files']);
        }
    }

    /** 清单不是摆设：升级排序器缺失必须让产物冒烟红。 */
    public function testMissingUpgradeEntryOrderFailsArtifactSmoke(): void
    {
        $root = $this->tempDir . '/yikaicms-v9.9.9';
        unlink($root . '/includes/UpgradeEntryOrder.php');

        $errors = (new ReleaseArtifactSmoke($this->manifest))->inspectDirectory($root);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('includes/UpgradeEntryOrder.php', implode("\n", $errors));
    }

    public function testZipInspectionUsesTheExtractedArtifactAndRejectsForbiddenFiles(): void
    {
        $root = $this->tempDir . '/yikaicms-v9.9.9';
        $zipPath = $this->tempDir . '/release.zip';
        $this->zipDirectory($root, $zipPath, 'yikaicms-v9.9.9');
        $smoke = new ReleaseArtifactSmoke($this->manifest);
        self::assertSame([], $smoke->inspect($zipPath));

        mkdir($root . '/tests', 0700, true);
        file_put_contents($root . '/tests/leak.php', '<?php');
        $this->zipDirectory($root, $zipPath, 'yikaicms-v9.9.9');
        self::assertStringContainsString('Forbidden release path: tests', implode("\n", $smoke->inspect($zipPath)));

        $this->removeTree($root . '/tests');
        mkdir($root . '/vendor/overtrue', 0700, true);
        file_put_contents($root . '/vendor/overtrue/stale.php', '<?php');
        $this->zipDirectory($root, $zipPath, 'yikaicms-v9.9.9');
        self::assertStringContainsString('Forbidden release path: vendor', implode("\n", $smoke->inspect($zipPath)));
    }

    public function testBuildAndCiRunTheZipLevelArtifactSmoke(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        $workflow = (string) file_get_contents(ROOT_PATH . '/.github/workflows/ci.yml');

        self::assertStringContainsString('"config/release-runtime.php"', $build);
        self::assertStringContainsString('"includes/FooterNavigation.php"', $build);
        self::assertStringContainsString('"includes/Pinyin.php"', $build);
        self::assertStringContainsString('"includes/pinyin/chars.php"', $build);
        self::assertStringNotContainsString('cp -r "$ROOT_DIR/vendor"', $build);
        self::assertStringContainsString('php tools/release-artifact-smoke.php "$VERIFY_ZIP_FILE"', $build);
        self::assertStringContainsString('php tools/release-artifact-smoke.php "$ZIP"', $workflow);
    }

    private function copyRuntimeFixture(string $target): void
    {
        foreach ($this->manifest['required_files'] as $path) {
            $source = ROOT_PATH . '/' . $path;
            self::assertFileExists($source, 'Source runtime dependency is missing: ' . $path);
            $destination = $target . '/' . $path;
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            copy($source, $destination);
        }
        foreach ($this->manifest['generated_files'] ?? [] as $path) {
            $destination = $target . '/' . $path;
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($destination, "<?php\nreturn [];\n");
        }
    }

    private function zipDirectory(string $root, string $zipPath, string $prefix): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                $relative = substr($item->getPathname(), strlen($root) + 1);
                $zip->addFile($item->getPathname(), $prefix . '/' . str_replace('\\', '/', $relative));
            }
        }
        $zip->close();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
