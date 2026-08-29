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
